<?php
/**
 * Phase 3 authenticated HTTP checks against the isolated test site only.
 * It deliberately submits only requests expected to be denied, so no financial
 * fixture is created, posted, deleted, reversed, or otherwise changed.
 */
require_once __DIR__ . '/includes/db.php';

if (getenv('DB_NAME') !== 'alghazali_refactor_test') {
    throw new RuntimeException('Refusing to run HTTP authorization tests outside alghazali_refactor_test.');
}

if (!function_exists('curl_init')) {
    throw new RuntimeException('PHP cURL is required for authenticated HTTP verification.');
}

$base = rtrim((string)(getenv('PHASE3_BASE_URL') ?: 'http://localhost:8080/alghazali/admin'), '/');
$password = 'Phase3LocalOnly!2026';
$fixture = [];
foreach ([1, 4] as $branchId) {
    $stmt = $pdo->prepare("SELECT id FROM financial_transactions WHERE branch_id = ? AND status IN ('posted', 'draft') ORDER BY id LIMIT 1");
    $stmt->execute([$branchId]);
    $fixture['voucher_' . $branchId] = (int)$stmt->fetchColumn();
}
if (!$fixture['voucher_4']) {
    // A draft fixture does not generate journal lines or change balances.  It is
    // retained for repeatable Branch-A / Branch-B object-authorization checks.
    $pdo->prepare("\n        INSERT INTO financial_transactions
            (transaction_number, transaction_date, branch_id, transaction_type,
             amount, currency_id, exchange_rate, status, created_by, description)
        VALUES ('P3-AUTH-BRANCH4-FIXTURE', CURDATE(), 4, 'receipt', 1, 1, 1,
                'draft', 24, 'Phase 3 isolated branch-authorization fixture')
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
    ")->execute();
    $fixture['voucher_4'] = (int)$pdo->lastInsertId();
}
if (!$fixture['voucher_1'] || !$fixture['voucher_4']) {
    throw new RuntimeException('Missing a required authorization test fixture.');
}

function request(string $url, ?array $post = null, ?string $cookieJar = null): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HEADER => false,
    ]);
    if ($cookieJar) {
        curl_setopt($curl, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($curl, CURLOPT_COOKIEFILE, $cookieJar);
    }
    if ($post !== null) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string)curl_exec($curl);
    $error = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($error !== '') throw new RuntimeException($error);
    return ['status' => $status, 'body' => $body];
}

function login(string $base, string $username, string $password): array
{
    $jar = tempnam(sys_get_temp_dir(), 'p3_auth_');
    $page = request($base . '/login.php', null, $jar);
    if (!preg_match('/name="csrf_token"\\s+value="([^"]+)"/', $page['body'], $matches)) {
        @unlink($jar);
        throw new RuntimeException("CSRF token not found for $username");
    }
    $result = request($base . '/login.php', [
        'username' => $username,
        'password' => $password,
        'csrf_token' => html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'),
    ], $jar);
    if ($result['status'] !== 200 || !str_contains($result['body'], 'QA ')) {
        @unlink($jar);
        throw new RuntimeException("Login failed for $username (HTTP {$result['status']})");
    }
    // Login regenerates the session ID but preserves its CSRF token.
    return ['jar' => $jar, 'csrf' => html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8')];
}

function check(array &$results, string $id, array $response, int $expected): void
{
    $actual = $response['status'];
    $results[] = [
        'id' => $id,
        'expected' => $expected,
        'actual' => $actual,
        'status' => $actual === $expected ? 'PASS' : 'FAIL',
        'body' => substr(trim(preg_replace('/\\s+/', ' ', strip_tags($response['body']))), 0, 160),
    ];
}

$results = [];
// Unauthenticated route checks.
check($results, 'unauth.voucher_detail', request($base . '/ajax/get_voucher_details.php?id=' . $fixture['voucher_1']), 401);
check($results, 'unauth.account_balances', request($base . '/ajax_get_account_balances.php'), 401);
check($results, 'unauth.exchange_post', request($base . '/ajax_post_exchange.php', ['id' => 0]), 401);

$sessions = [];
try {
    foreach (['employee', 'agent', 'accountant', 'branch_manager', 'accounts_manager', 'box_manager', 'admin', 'developer'] as $role) {
        $sessions[$role] = login($base, 'qa_' . $role . '_20260811', $password);
    }

    // Positive control: global admin can read an in-scope voucher.
    check($results, 'admin.read.branch1', request($base . '/ajax/get_voucher_details.php?id=' . $fixture['voucher_1'], null, $sessions['admin']['jar']), 200);

    // Object-level / branch isolation control: branch-1 accounts manager must not read branch-4 voucher.
    check($results, 'accounts_manager.read.branch4', request($base . '/ajax/get_voucher_details.php?id=' . $fixture['voucher_4'], null, $sessions['accounts_manager']['jar']), 403);
    check($results, 'accountant.read.branch4', request($base . '/ajax/get_voucher_details.php?id=' . $fixture['voucher_4'], null, $sessions['accountant']['jar']), 403);

    // Vertical privilege checks: all use a valid session CSRF token and an existing branch-1 voucher.
    foreach (['employee', 'agent', 'box_manager'] as $role) {
        $csrf = $sessions[$role]['csrf'];
        $jar = $sessions[$role]['jar'];
        check($results, "$role.post", request($base . '/ajax/post_voucher.php', ['id' => $fixture['voucher_1'], 'csrf_token' => $csrf], $jar), 403);
        check($results, "$role.reverse", request($base . '/ajax/reverse_voucher.php', ['id' => $fixture['voucher_1'], 'reason' => 'Phase 3 denied-action test', 'csrf_token' => $csrf], $jar), 403);
        check($results, "$role.unpost", request($base . '/ajax/unpost_voucher.php', ['id' => $fixture['voucher_1'], 'csrf_token' => $csrf], $jar), 403);
        check($results, "$role.delete", request($base . '/ajax/delete_voucher.php', ['id' => $fixture['voucher_1'], 'csrf_token' => $csrf], $jar), 403);
        check($results, "$role.exchange_post", request($base . '/ajax_post_exchange.php', ['id' => 0, 'csrf_token' => $csrf], $jar), 403);
        check($results, "$role.exchange_unpost", request($base . '/ajax_unpost_exchange.php', ['id' => 0, 'csrf_token' => $csrf], $jar), 403);
    }
} finally {
    foreach ($sessions as $session) if (is_file($session['jar'])) @unlink($session['jar']);
}

$failed = 0;
foreach ($results as $result) {
    echo json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
    if ($result['status'] !== 'PASS') $failed++;
}
echo 'SUMMARY total=' . count($results) . ' failed=' . $failed . "\n";
exit($failed === 0 ? 0 : 1);
