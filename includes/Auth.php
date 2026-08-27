<?php
declare(strict_types=1);

namespace App\Includes;

use App\Classes\Database;
use PDO;

final class Auth
{
    private AuditLogger $audit;
    private NotificationService $notifications;

    public function __construct(private Database $database)
    {
        $this->audit = new AuditLogger($database);
        $this->notifications = new NotificationService($database);
        $this->ensureProfileImageColumn();
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

        if ($email === false) {
            Response::error('تحقق من الاسم والبريد والهاتف وكلمة المرور.', 'VALIDATION_ERROR', 422);
        }
        if (!$countryId || !$cityId) {
            $default = $this->database->pdo()->query("SELECT c.id AS city_id, c.country_id FROM cities c INNER JOIN countries co ON co.id = c.country_id WHERE c.is_active = 1 AND co.is_active = 1 ORDER BY c.id LIMIT 1")->fetch();
            if (is_array($default)) { $countryId = (int) $default['country_id']; $cityId = (int) $default['city_id']; }
        }
        if (!$countryId || !$cityId) Response::error('لا توجد مدينة متاحة لإنشاء الحساب حاليًا.', 'VALIDATION_ERROR', 422);
        PasswordPolicy::validate($this->database, $password);

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
        $this->notifications->sendToCustomerManagers('عميل جديد', "تم إنشاء حساب العميل {$result['full_name']} ويحتاج إلى المتابعة من إدارة العملاء.", (int) $result['id']);
        return $this->currentUser() ?? $result;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function registerCustomerAfterOtp(array $payload): array
    {
        $fullName = Security::cleanText($payload['full_name'] ?? null, 180);
        $email = filter_var(trim((string) ($payload['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $phone = Security::cleanText($payload['phone'] ?? null, 32);
        $countryId = (int) ($payload['country_id'] ?? 0);
        $cityId = (int) ($payload['city_id'] ?? 0);
        $passwordHash = trim((string) ($payload['password_hash'] ?? ''));
        if ($email === false || $countryId < 1 || $cityId < 1 || $passwordHash === '') {
            Response::error('تعذر إكمال إنشاء الحساب بعد التحقق.', 'OTP_REGISTRATION_INVALID', 422);
        }
        $result = $this->database->transaction(function (PDO $pdo) use ($fullName, $email, $phone, $countryId, $cityId, $passwordHash): array {
            $country = $pdo->prepare('SELECT id FROM countries WHERE id = :id AND is_active = 1');
            $country->execute(['id' => $countryId]);
            $city = $pdo->prepare('SELECT id FROM cities WHERE id = :id AND country_id = :country_id AND is_active = 1');
            $city->execute(['id' => $cityId, 'country_id' => $countryId]);
            if (!$country->fetch() || !$city->fetch()) Response::error('الدولة أو المدينة المختارة لم تعد متاحة.', 'VALIDATION_ERROR', 422);
            $exists = $pdo->prepare('SELECT id FROM users WHERE email = :email OR phone = :phone LIMIT 1');
            $exists->execute(['email' => $email, 'phone' => $phone]);
            if ($exists->fetch()) Response::error('البريد الإلكتروني أو رقم الهاتف مسجل مسبقًا.', 'DUPLICATE_ACCOUNT', 409);
            $user = $pdo->prepare('INSERT INTO users (full_name, email, phone, password_hash) VALUES (:full_name, :email, :phone, :password_hash)');
            $user->execute(['full_name' => $fullName, 'email' => $email, 'phone' => $phone, 'password_hash' => $passwordHash]);
            $userId = (int) $pdo->lastInsertId();
            $roleId = (int) $pdo->query("SELECT id FROM roles WHERE code = 'customer' LIMIT 1")->fetchColumn();
            if ($roleId < 1) Response::error('دور العميل غير مهيأ في النظام.', 'SERVER_ERROR', 500);
            $pdo->prepare('INSERT INTO user_roles (user_id, role_id, company_id) VALUES (:user_id, :role_id, NULL)')->execute(['user_id' => $userId, 'role_id' => $roleId]);
            $pdo->prepare('INSERT INTO customers (user_id, country_id, city_id) VALUES (:user_id, :country_id, :city_id)')->execute(['user_id' => $userId, 'country_id' => $countryId, 'city_id' => $cityId]);
            return ['id' => $userId, 'full_name' => $fullName, 'email' => $email];
        });
        $this->createSession((int) $result['id']);
        $this->audit->log((int) $result['id'], null, 'customer_registered', 'user', (int) $result['id']);
        $this->notifications->sendToCustomerManagers('عميل جديد', "تم إنشاء حساب العميل {$result['full_name']} بعد التحقق من OTP.", (int) $result['id']);
        return $this->currentUser() ?? $result;
    }

    /** @return array<string,mixed> */
    public function loginAfterOtp(int $userId): array
    {
        if ($userId < 1) Response::error('حساب المستخدم غير صالح.', 'OTP_LOGIN_INVALID', 422);
        $statement = $this->database->pdo()->prepare('SELECT id, status FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row) || (string) $row['status'] !== 'active') Response::error('حسابك غير نشط حاليًا.', 'ACCOUNT_INACTIVE', 403);
        $this->createSession($userId);
        $this->database->pdo()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')->execute(['id' => $userId]);
        $this->audit->log($userId, $this->companyIdForUser($userId), 'user_logged_in_otp', 'user', $userId);
        return $this->currentUser() ?? [];
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

    /** @return array<string, mixed> */
    public function updateCurrentUser(array $actor, array $input): array
    {
        $userId = (int) ($actor['id'] ?? 0);
        if ($userId < 1) {
            Response::error('جلسة المستخدم غير صالحة.', 'UNAUTHORIZED', 401);
        }
        $fullName = Security::cleanText($input['full_name'] ?? null, 180);
        $email = filter_var(trim((string) ($input['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $phone = Security::cleanText($input['phone'] ?? null, 32);
        $newPassword = (string) ($input['password'] ?? '');
        if (mb_strlen($fullName) < 3 || $email === false || mb_strlen($phone) < 5) {
            Response::error('تحقق من الاسم والبريد الإلكتروني ورقم الهاتف.', 'VALIDATION_ERROR', 422);
        }
        if ($newPassword !== '') PasswordPolicy::validate($this->database, $newPassword);
        $pdo = $this->database->pdo();
        $duplicate = $pdo->prepare('SELECT id FROM users WHERE (email = :email OR phone = :phone) AND id <> :id LIMIT 1');
        $duplicate->execute(['email' => $email, 'phone' => $phone, 'id' => $userId]);
        if ($duplicate->fetch()) {
            Response::error('البريد الإلكتروني أو رقم الهاتف مستخدم لحساب آخر.', 'DUPLICATE_ACCOUNT', 409);
        }
        $sql = 'UPDATE users SET full_name = :full_name, email = :email, phone = :phone';
        $params = ['full_name' => $fullName, 'email' => $email, 'phone' => $phone, 'id' => $userId];
        if ($newPassword !== '') {
            $sql .= ', password_hash = :password_hash';
            $params['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE id = :id';
        $pdo->prepare($sql)->execute($params);
        $this->audit->log($userId, $actor['company_id'] ? (int) $actor['company_id'] : null, 'user_profile_updated', 'user', $userId, null, ['password_changed' => $newPassword !== '']);
        return $this->currentUser() ?? [];
    }

    /** @return array<string, mixed>|null */
    public function customerProfile(array $actor): ?array
    {
        $customerId = (int) ($actor['customer_id'] ?? 0);
        if ($customerId < 1) {
            Response::error('بياناتي متاحة للعملاء المسجلين فقط.', 'FORBIDDEN', 403);
        }
        $statement = $this->database->pdo()->prepare(
            'SELECT id, full_name_ar, gender, phone_country_code, phone, passport_number, birth_date, birth_place, passport_issue_date, passport_issue_place
             FROM passengers WHERE customer_id = :customer_id ORDER BY updated_at DESC, id DESC LIMIT 1'
        );
        $statement->execute(['customer_id' => $customerId]);
        $profile = $statement->fetch();
        return is_array($profile) ? $profile : null;
    }

    /** @return array<string, mixed> */
    public function updateCustomerProfile(array $actor, array $input): array
    {
        $customerId = (int) ($actor['customer_id'] ?? 0);
        if ($customerId < 1) {
            Response::error('بياناتي متاحة للعملاء المسجلين فقط.', 'FORBIDDEN', 403);
        }
        $profile = $this->normalizeCustomerProfile($input);
        $pdo = $this->database->pdo();
        $existing = $pdo->prepare('SELECT id FROM passengers WHERE customer_id = :customer_id ORDER BY updated_at DESC, id DESC LIMIT 1');
        $existing->execute(['customer_id' => $customerId]);
        $passengerId = (int) ($existing->fetchColumn() ?: 0);
        if ($passengerId > 0) {
            $pdo->prepare(
                'UPDATE passengers SET full_name_ar = :full_name_ar, gender = :gender, phone_country_code = :phone_country_code,
                 phone = :phone, passport_number = :passport_number, birth_date = :birth_date, birth_place = :birth_place,
                 passport_issue_date = :passport_issue_date, passport_issue_place = :passport_issue_place WHERE id = :id AND customer_id = :customer_id'
            )->execute([...$profile, 'id' => $passengerId, 'customer_id' => $customerId]);
        } else {
            $pdo->prepare(
                'INSERT INTO passengers (customer_id, full_name_ar, gender, phone_country_code, phone, passport_number, birth_date, birth_place, passport_issue_date, passport_issue_place)
                 VALUES (:customer_id, :full_name_ar, :gender, :phone_country_code, :phone, :passport_number, :birth_date, :birth_place, :passport_issue_date, :passport_issue_place)'
            )->execute(['customer_id' => $customerId, ...$profile]);
            $passengerId = (int) $pdo->lastInsertId();
        }
        $this->audit->log((int) $actor['id'], null, 'customer_profile_updated', 'passenger', $passengerId);
        return $this->customerProfile($actor) ?? ['id' => $passengerId, ...$profile];
    }

    /** @return array<string, string> */
    private function normalizeCustomerProfile(array $input): array
    {
        $fullName = Security::cleanText($input['full_name_ar'] ?? null, 220);
        $gender = (string) ($input['gender'] ?? '');
        $phoneCountryCode = Security::cleanText($input['phone_country_code'] ?? null, 10);
        $phone = Security::cleanText($input['phone'] ?? null, 32);
        $passport = strtoupper(Security::cleanText($input['passport_number'] ?? null, 64));
        $birthDate = trim((string) ($input['birth_date'] ?? ''));
        $birthPlace = Security::cleanText($input['birth_place'] ?? null, 180);
        $issueDate = trim((string) ($input['passport_issue_date'] ?? ''));
        $issuePlace = Security::cleanText($input['passport_issue_place'] ?? null, 180);
        $validDate = static function (string $value): bool {
            if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value)) return false;
            [$year, $month, $day] = array_map('intval', explode('-', $value));
            return checkdate($month, $day, $year);
        };
        if (mb_strlen($fullName) < 3 || !in_array($gender, ['male', 'female'], true) || mb_strlen($phoneCountryCode) < 2 || mb_strlen($phone) < 5 || $passport === '' || !$validDate($birthDate) || $birthPlace === '' || !$validDate($issueDate) || $issuePlace === '') {
            Response::error('تحقق من جميع بيانات المسافر المطلوبة.', 'VALIDATION_ERROR', 422);
        }
        return [
            'full_name_ar' => $fullName,
            'gender' => $gender,
            'phone_country_code' => $phoneCountryCode,
            'phone' => $phone,
            'passport_number' => $passport,
            'birth_date' => $birthDate,
            'birth_place' => $birthPlace,
            'passport_issue_date' => $issueDate,
            'passport_issue_place' => $issuePlace,
        ];
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

    /** @return array<string, mixed> */
    public function uploadProfileImage(array $actor, array $file): array
    {
        $userId = (int) ($actor['id'] ?? 0);
        if ($userId < 1 || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::error('اختر صورة صحيحة أولًا.', 'VALIDATION_ERROR', 422);
        }
        if ((int) ($file['size'] ?? 0) > 2 * 1024 * 1024) Response::error('حجم الصورة يجب ألا يتجاوز 2 ميجابايت.', 'VALIDATION_ERROR', 422);
        $image = @getimagesize((string) ($file['tmp_name'] ?? ''));
        $mime = (string) ($image['mime'] ?? '');
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime]) || (int) ($image[0] ?? 0) > 3000 || (int) ($image[1] ?? 0) > 3000) {
            Response::error('صيغة الصورة غير مدعومة أو أبعادها كبيرة.', 'VALIDATION_ERROR', 422);
        }
        $folder = APP_ROOT . '/uploads/profiles';
        if (!is_dir($folder) && !mkdir($folder, 0755, true) && !is_dir($folder)) Response::error('تعذر تجهيز مجلد الصور.', 'SERVER_ERROR', 500);
        $relative = 'uploads/profiles/user_' . $userId . '_' . bin2hex(random_bytes(5)) . '.' . $extensions[$mime];
        if (!move_uploaded_file((string) $file['tmp_name'], APP_ROOT . '/' . $relative)) Response::error('تعذر حفظ صورة المستخدم.', 'SERVER_ERROR', 500);
        $this->database->pdo()->prepare('UPDATE users SET profile_image_path = :path WHERE id = :id')->execute(['path' => $relative, 'id' => $userId]);
        $this->audit->log($userId, null, 'user_profile_image_updated', 'user', $userId, null, ['path' => $relative]);
        return ['profile_image_path' => $relative];
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
        $statement = $this->database->pdo()->prepare('SELECT id, full_name, email, phone, profile_image_path, status FROM users WHERE id = :id AND status = \'active\' LIMIT 1');
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
            'profile_image_path' => $user['profile_image_path'] ?? null,
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

    private function ensureProfileImageColumn(): void
    {
        $exists = $this->database->pdo()->query("SHOW COLUMNS FROM users LIKE 'profile_image_path'")->fetch();
        if (!$exists) $this->database->pdo()->exec("ALTER TABLE users ADD profile_image_path VARCHAR(255) NULL AFTER phone");
    }
}
