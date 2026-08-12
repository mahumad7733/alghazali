Exit code: 0
Wall time: 2.6 seconds
Total output lines: 1364
Output:
<?php

require_once __DIR__ . '/../includes/accounting_functions.php';

/**
 * Finance Core Module
 * ط§ظ„ظ…طµط¯ط± ط§ظ„ظ…ط±ظƒط²ظٹ ط§ظ„ظˆط­ظٹط¯ ظ„ط¬ظ…ظٹط¹ ط¹ظ…ظ„ظٹط§طھ ط§ظ„ظ…ط§ظ„ظٹط© ط¯ط§ط®ظ„ ط§ظ„ظ†ط¸ط§ظ….
 *
 * ###################################################################
 *  طھط­ط¯ظٹط«ط§طھ ط£ظ…ط§ظ† ظˆط§ط³طھظ‚ط±ط§ط± (طھظڈط·ط¨ظ‘ظ‚ ط¨ط¯ظˆظ† ظƒط³ط± ط£ظٹ ظˆط§ط¬ظ‡ط© ط¹ط§ظ…ط© ط£ظˆ ظ‚ظٹظ…ط© ظ…ط±ط¬ط¹ط©)
 *  v2 (Backward-Compatible)
 * ###################################################################
 *  âœ… 1. Idempotency Check (ط®ظپظٹظپ: ط¥ظ† طھظˆظپط± ظ…ظپطھط§ط­ idempotency_key ظپظٹ ط§ظ„ط¨ظٹط§ظ†ط§طھ)
 *  âœ… 2. ظ…ظ†ط¹ ط¥ظ†ط´ط§ط، ط³ظ†ط¯ ظ‚ط¨ط¶/طµط±ظپ ظ…ظƒط±ط± ظ„ظ†ظپط³ ط§ظ„ط¹ظ…ظ„ظٹط© ظˆظ†ظپط³ ط§ظ„ظ…ط¨ظ„ط؛ ظˆط§ظ„ط·ط±ظپ
 *  âœ… 3. ظ…ظ†ط¹ ط§ظ„ط¯ظپط¹ ط§ظ„ظ…ظƒط±ط± (ظ†ظپط³ ط§ظ„ظپط§طھظˆط±ط© طھظڈط¯ظپط¹ ظ…ط±طھظٹظ† ط¨ظ†ظپط³ ط§ظ„ط³ظ†ط¯)
 *  âœ… 4. ظ…ظ†ط¹ طھط®طµظٹطµ ظ†ظپط³ ط§ظ„ط¯ظپط¹ط© ط£ظƒط«ط± ظ…ظ† ظ…ط±ط© (UNIQUE ظپط¹ظ„ظٹ ط¹ط¨ط± SELECT)
 *  âœ… 5. ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† ط§ظ„ط±طµظٹط¯ ط§ظ„ظ…طھط¨ظ‚ظٹ ظ‚ط¨ظ„ ط§ظ„طھط®طµظٹطµ
 *  âœ… 6. ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† ط­ط§ظ„ط© ط§ظ„ظپط§طھظˆط±ط© ظ‚ط¨ظ„ ط§ظ„ط³ط¯ط§ط¯
 *  âœ… 7. طµظ„ط§ط­ظٹط© ط§ظ„ظ…ط³طھط®ط¯ظ… (hook ط¢ظ…ظ† ط¹ط¨ط± ط§ظ„ط¯ط§ظ„ط© ط§ظ„ظ…ظˆط¬ظˆط¯ط© ظپظٹ accounting_functions ط£ظˆ ط§ط®طھطµط§طµط§طھ ط§ظ„ط¬ظ„ط³ط©)
 *  âœ… 8. ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† ط§ظ„ظپطھط±ط© ط§ظ„ظ…ط§ظ„ظٹط© ط§ظ„ظ…ط؛ظ„ظ‚ط© (ط¬ط¯ظˆظ„ fiscal_periods ط§ظ„ظ…ظˆط¬ظˆط¯ ظپط¹ظ„ط§ظ‹)
 *  âœ… 9. ظ…ط¹ط§ظ„ط¬ط© Race Conditions ط¹ط¨ط± SELECT ... FOR UPDATE / FOR SHARE
 *  âœ… 10. SELECT ... FOR UPDATE ط¹ظ†ط¯ ط§ظ„ط­ط§ط¬ط© (ظپط§طھظˆط±ط© + ط£ط±طµط¯ط© + طھط®طµظٹطµط§طھ)
 *  âœ… 11. ط¥طµظ„ط§ط­ ظ…ط´ظƒظ„ط© Nested Transactions (Savepoints ط¯ط§ط®ظ„ ensure*)
 *  âœ… 12. طھط­ط³ظٹظ† ط¥ط¯ط§ط±ط© Commit / Rollback (ظ„ط§ ظٹطھظ… commit ط®ط§ط±ط¬ظٹط§ظ‹ ط¯ط§ط®ظ„ ط¯ظˆط§ظ„ ط®ط§طµط©)
 *  âœ… 13. ط¥ط¶ط§ظپط© Audit Log (ط¬ط¯ظˆظ„ audit_logs ظ…ظˆط¬ظˆط¯ ط¨ط§ظ„ظپط¹ظ„)
 *  âœ… 14. Validation ط¥ط¶ط§ظپظٹط© ظ…ظپظ‚ظˆط¯ط© (ط­ط³ط§ط¨ ظ†ط´ط·طں ط؛ظٹط± ظ…ط¬ظ…ط¯طں ط§ظ„ط¹ظ…ظ„ط© طµط§ظ„ط­ط©طں ...)
 *  âœ… 15. طھط­ط³ظٹظ† ط§ظ„ط£ط¯ط§ط، (cache ظ„ظ€ resolvePartyAccountId + طھط¬ظ†ط¨ ط§ط³طھط¹ظ„ط§ظ…ط§طھ ظ…ظƒط±ط±ط©)
 * ###################################################################
 */
class LegacyFinanceService
{
    /** @var PDO */
    private $pdo;

    /** @var int */
    private $userId;

    /**
     * Cache ط¯ط§ط®ظ„ظٹ ظ„ط­ط³ط§ط¨ط§طھ ط§ظ„ط£ط·ط±ط§ظپ ظ„طھظ‚ظ„ظٹظ„ ط§ظ„ط§ط³طھط¹ظ„ط§ظ…ط§طھ ط§ظ„ظ…طھظƒط±ط±ط©
     * ظپظٹ ظ†ظپط³ ط§ظ„ط·ظ„ط¨ (ط؛ظٹط± ط¯ط§ط¦ظ… â€” ظٹط®طھظپظٹ ط¨ط¹ط¯ ط§ظ†طھظ‡ط§ط، ط§ظ„ط·ظ„ط¨).
     * @var array<string,?int>
     */
    private static $partyAccountCache = [];

    /**
     * Savepoint stack ظ„طھظپط§ط¯ظٹ ظ…ط´ط§ظƒظ„ ط§ظ„ظ€ Nested Transactions.
     * @var array<int,string>
     */
    private $savepointStack = [];

    public function __construct(PDO $pdo, ?int $userId = null)
    {
        $this->pdo = $pdo;
        $this->userId = (int)($userId ?: ($_SESSION['admin_id'] ?? 1));
    }

    // =================================================================
    // آ§آ§  ط¯ط¹ظ… ط§ظ„ظپطھط±ط§طھ ط§ظ„ظ…ط؛ظ„ظ‚ط© + طµظ„ط§ط­ظٹط§طھ ط§ظ„ظ…ط³طھط®ط¯ظ… + طھط¯ظ‚ظٹظ‚
    // =================================================================

    /**
     * ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† ط£ظ† طھط§ط±ظٹط® ط§ظ„ط¹ظ…ظ„ظٹط© ظٹظ‚ط¹ ظپظٹ ظپطھط±ط© ظ…ط§ظ„ظٹط© ط؛ظٹط± ظ…ط؛ظ„ظ‚ط©.
     * @throws Exception ط¥ط°ط§ ظƒط§ظ†طھ ط§ظ„ظپطھط±ط© ط§ظ„ظ…ط§ظ„ظٹط© ظ…ط؛ظ„ظ‚ط©.
     */
    private function assertFiscalPeriodOpen(?string $operationDate): void
    {
        if (!$operationDate) {
            $operationDate = date('Y-m-d');
        }
        $dateOnly = substr($operationDate, 0, 10);

        try {
            $stmt = $this->pdo->prepare("
                SELECT id, period_name, is_closed
                FROM fiscal_periods
                WHERE ? BETWEEN start_date AND end_date
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$dateOnly]);
            $period = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$period) {
                throw new Exception("No fiscal period found for {$dateOnly}");
            }
            if ($period && !empty($period['is_closed'])) {
                throw new Exception("ط§ظ„ظپطھط±ط© ط§ظ„ظ…ط§ظ„ظٹط© آ«{$period['period_name']}آ» ظ…ط؛ظ„ظ‚ط©. ظ„ط§ ظٹظ…ظƒظ† طھط³ط¬ظٹظ„ ط¹ظ…ظ„ظٹط§طھ ظ…ط§ظ„ظٹط© ط¯ط§ط®ظ„ظ‡ط§.");
            }
        } catch (Throwable $e) {
            if (mb_strpos($e->getMessage(), 'ط§ظ„ظپطھط±ط© ط§ظ„ظ…ط§ظ„ظٹط©') !== false || mb_strpos($e->getMessage(), 'ظ…ط؛ظ„ظ‚ط©') !== false) {
                throw $e;
            }
            // طھط¬ط§ظ‡ظ„ ط§ظ„ط®ط·ط£ ط¥ظ† ظƒط§ظ† ط¬ط¯ظˆظ„ fiscal_periods ط؛ظٹط± ظ…ظˆط¬ظˆط¯ ط£ظˆ ظ…ط´ظƒظ„ط© ط¨ط³ظٹط·ط©
            // (ظ„ظ„ط­ظپط§ط¸ ط¹ظ„ظ‰ ط§ظ„طھظˆط§ظپظ‚ ظ…ط¹ ط§ظ„ط¥طµط¯ط§ط±ط§طھ ط§ظ„ظ‚ط¯ظٹظ…ط© ط§ظ„طھظٹ ظ‚ط¯ ظ„ط§ طھط­طھظˆظٹظ‡).
        }
    }

    /**
     * ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† طµظ„ط§ط­ظٹط© ط§ظ„ظ…ط³طھط®ط¯ظ… ظ„ظ„ط¹ظ…ظ„ظٹط© ط§ظ„ظ…ط§ظ„ظٹط© (hook ط¢ظ…ظ† ط§ط®طھظٹط§ط±ظٹ).
     * â€” ط¥ظ† ظ„ظ… ظٹظƒظ† ظ‡ظ†ط§ظƒ ظ†ط¸ط§ظ… طµظ„ط§ط­ظٹط§طھ ظ…ظپط¹ظ‘ظ„طŒ ظٹظڈظ…ط±ط± ط¨ط¯ظˆظ† ط£ظٹ ظپط´ظ„.
     * â€” ط¥ظ† طھظˆظپط± ط§ظ„ط¯ط§ظ„ط© has_permission ظ…ظ† accounting_functions.php ط£ظˆ ظ…ظ† ط§ظ„ط¬ظ„ط³ط©
     *   ظٹطھظ… ط§ط³طھط¯ط¹ط§ط¤ظ‡ط§طŒ ظˆط¥ظ„ط§ ظٹظڈط¹طھط¨ط± ط§ظ„ظ…ط³طھط®ط¯ظ… ظ…ظڈط®ظˆظ‘ظ„ط§ظ‹ (ظ„ظ„ط§ط³طھظ‚ط±ط§ط±).
     */
    private function assertUserCan(string $permission, string $operation): void
    {
        try {
            if (function_exists('has_permission')) {
                if (!has_permission($this->userId, $permission)) {
                    throw new Exception("ظ„ظٹط³ ظ„ط¯ظٹظƒ طµظ„ط§ط­ظٹط© ظ„ظ„ظ‚ظٹط§ظ… ط¨ظ€: $operation");
                }
                return;
            }

            if (!empty($_SESSION['_permissions']) && is_array($_SESSION['_permissions'])) {
                if (
                    !in_array($permission, $_SESSION['_permissions'], true)
                    && !in_array('*', $_SESSION['_permissions'], true)
                    && !in_array('super_admin', $_SESSION['_permissions'], true)
                ) {
                    throw new Exception("ظ„ظٹط³ ظ„ط¯ظٹظƒ طµظ„ط§ط­ظٹط© ظ„ظ„ظ‚ظٹط§ظ… ط¨ظ€: $operation");
                }
            }
        } catch (Throwable $e) {
            if (mb_strpos($e->getMessage(), 'طµظ„ط§ط­ظٹط©') !== false) {
                throw $e;
            }
            // ط£ظٹ ط®ط·ط£ ط¢ط®ط± (ظ…ط«ط§ظ„: ظ„ط§ ظٹظˆط¬ط¯ ط¬ط¯ظˆظ„ طµظ„ط§ط­ظٹط§طھ) â†’ ظ„ط§ ظ†ظ…ظ†ط¹ ط§ظ„ط¹ظ…ظ„ظٹط©
            // ظ„ظ„ط­ظپط§ط¸ ط¹ظ„ظ‰ ط§ظ„طھظˆط§ظپظ‚ ظ…ط¹ ط§ظ„ظ†ط¸ط§ظ… ط§ظ„ط­ط§ظ„ظٹ.
        }
    }

    /**
     * ظƒطھط§ط¨ط© ط³ط¬ظ„ طھط¯ظ‚ظٹظ‚ ظ…ط±ظƒط²ظٹ ظپظٹ audit_logs (ط§ظ„ط¬ط¯ظˆظ„ ظ…ظˆط¬ظˆط¯ ظپط¹ظ„ظٹط§ظ‹ ظپظٹ ط§ظ„ظ†ط¸ط§ظ…).
     * â€” ط¢ظ…ظ† طھظ…ط§ظ…ط§ظ‹: ط£ظٹ ط®ط·ط£ ظٹطھظ… طھط¬ط§ظ‡ظ„ظ‡ ظˆظ„ط§ ظٹظ…ظ†ط¹ ط§ظ„ط¹ظ…ظ„ظٹط© ط§ظ„ط£طµظ„ظٹط© ظ…ظ† ط§ظ„ط¥ظƒظ…ط§ظ„.
     */
    private function writeAudit(string $action, string $entity, ?int $entityId, array $extra = []): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO audit_logs
                    (user_id, action, entity_type, entity_id, ip_address, user_agent, created_at, details_json)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
            ");

            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

            if (!$extra) {
                $details = null;
            } else {
                $json = json_encode($extra, JSON_UNESCAPED_UNICODE);
                $details = $json === false ? null : $json;
            }

            $stmt->execute([
                $this->userId,
                $action,
                $entity,
                $entityId,
                $ip,
                $ua,
                $details,
            ]);
        } catch (Throwable $e) {
            // ط§ظ„طھط¬ط§ظ‡ظ„ ط§ظ„ط¢ظ…ظ†. ظƒطھط§ط¨ط© ط§ظ„ظ€ Audit ظ„ط§ ظٹط¬ط¨ ط£ط¨ط¯ط§ظ‹ ط£ظ† طھظƒط³ط± ط§ظ„ط¹ظ…ظ„ظٹط© ط§ظ„ظ…ط§ظ„ظٹط© ط§ظ„ط£طµظ„ظٹط©.
            error_log('FinanceService::writeAudit FAILED: ' . $e->getMessage());
        }
    }

    // =================================================================
    // آ§آ§  ط¯ط¹ظ… Nested Transactions ط§ظ„ط¢ظ…ظ† ط¹ط¨ط± Savepoints
    // =================================================================

    /**
     * ط¥ظ†ط´ط§ط، ظ…ظ†ط·ظ‚ط© ط­ظپط¸ (Savepoint) ط¯ط§ط®ظ„ ط§ظ„طھط±ط§ظ†ط²ط§ظƒط³ ط§ظ„ط¬ط§ط±ظٹ ط¥ظ† ظˆط¬ط¯طŒ ط£ظˆ ط¨ط¯ط، طھط±ط§ظ†ط²ط§ظƒط³ ط¬ط¯ظٹط¯.
     * â€” طھط­ظ„ ظ†ظ‡ط§ط¦ظٹط§ظ‹ ظ…ط´ظƒظ„ط© "There is already an active transaction"
     *   ط§ظ„طھظٹ ظƒط§ظ†طھ طھط­ط¯ط« ط¹ظ†ط¯ ط§ط³طھط¯ط¹ط§ط، ensureCustomerAccount ط¯ط§ط®ظ„ executeAtomically.
     */
    private function safeBegin(): string
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $name = '__TOP__';
            $this->savepointStack[] = $name;
            return $name;
        }

        $name = 'sp_fs_' . uniqid('', false);
        try {
            $this->pdo->exec("SAVEPOINT `$name`");
        } catch (Throwable $e) {
            $name = '__NONE__'; // ظ…ط­ط§ظƒظ…ط© ظ†ط§ط¬ط­ط© ط¹ظ„ظ‰ ط§ظ„ط£ظ†ط¸ظ…ط© ط§ظ„طھظٹ ظ„ط§ طھط¯ط¹ظ… SAVEPOINT ط¯ط§ط®ظ„ SP
        }
        $this->savepointStack[] = $name;
        return $name;
    }

    /**
     * ط¥ظ†ظ‡ط§ط، ظ…ظ†ط·ظ‚ط© ط­ظپط¸ (Savepoint) ط¨ط·ط±ظٹظ‚ط© ط¢ظ…ظ†ط©.
     * â€” ط¥ظ† ظƒط§ظ† ظ‡ظ†ط§ظƒ ط­ظپط¸ ط¹ظ„ظˆظٹ ظٹطھظ… COMMIT ظپظ‚ط· ظ„ط£ط¹ظ„ظ‰ ظ†ظ‚ط·ط© (TOP).
     * â€” $commit = ظٹط¹ظ†ظٹ ط§ظ„ظ†ط¬ط§ط­طŒ ظˆ false ظٹط¹ظ†ظٹ ط§ظ„طھط±ط§ط¬ط¹ ظ„ظ„ظ†ظ‚ط·ط©.
     */
    private function safeEnd(string $name, bool $commit): void
    {
        $stackIdx = array_search($name, $this->savepointStack, true);
        if ($stackIdx === false) {
            return;
        }

        while (count($this->savepointStack) > $stackIdx) {
            $n = array_pop($this->savepointStack);
            if ($n === '__TOP__') {
                if ($this->pdo->inTransaction()) {
                    if ($commit) {
                        $this->pdo->commit();
                    } else {
                        $this->pdo->rollBack();
                    }
                }
                return;
            }
            if ($n === '__NONE__' || $n === '') {
                continue;
            }
            try {
                if ($commit) {
                    $this->pdo->exec("RELEASE SAVEPOINT `$n`");
                } else {
                    $this->pdo->exec("ROLLBACK TO SAVEPOINT `$n`");
                }
            } catch (Throwable $e) {
                // طھط¬ط§ظ‡ظ„. ط§ظ„ط£ظ‡ظ… ط£ظ† ظ„ط§ ظٹظڈظƒط³ط± ط§ظ„طھط±ط§ظ†ط²ط§ظƒط³ ط§ظ„ط®ط§ط±ط¬ظٹ (ط¥ظ† ظˆط¬ط¯).
            }
        }
    }

    // =================================================================
    // آ§آ§  طھظˆط­ظٹط¯ ط£ط³ظ…ط§ط، ط§ظ„ط­ظ‚ظˆظ„ ط§ظ„ظ…ط§ظ„ظٹط©
    // =================================================================

    /**
     * طھظˆط­ظٹط¯ ط£ط³ظ…ط§ط، ط§ظ„ط­ظ‚ظˆظ„ ط§ظ„ظ…ط§ظ„ظٹط© ط¯ط§ط®ظ„ ط§ظ„ظ†ط¸ط§ظ….
     * [ط§ظ„طھظˆط§ظپظ‚ 100%] â€” ظˆط§ط¬ظ‡ط© ظ†ظپط³ ط§ظ„ظ‚ط¯ظٹظ… + ط¥ط¶ط§ظپط© ط­ظ‚ظˆظ„ طھط­ظ‚ظ‚ ط¬ط¯ظٹط¯ط© ظ„ظ„ظ†ط³ط®ط© ط§ظ„ط«ط§ظ†ظٹط©.
     */
    public function normalizeFinancialPayload(array $data): array
    {
        $discountAmount = (float)($data['discount_amount'] ?? $data['discount'] ?? 0);
        $paidAmount = (float)($data['paid_amount'] ?? $data['received_amount'] ?? $data['amount_received'] ?? 0);

        $saleTotal = (float)($data['sale_total_amount'] ?? $data['total_amount'] ?? $data['sale_price'] ?? 0);
        $purchaseTotal = (float)($data['purchase_total_amount'] ?? $data['purchase_price'] ?? 0);
        $taxAmount = (float)($data['tax_amount'] ?? 0);

        $netAmount = max(0.0, $saleTotal - $discountAmount + $taxAmount);

        $normalized = [
            'branch_id' => isset($data['branch_id']) ? (int)$data['branch_id'] : null,
            'source_type' => $data['source_type'] ?? $data['service_type'] ?? null,
            'source_id' => isset($data['source_id']) ? (int)$data['source_id'] : null,
            'customer_id' => isset($data['customer_id']) ? (int)$data['customer_id'] : null,
            'supplier_id' => isset($data['supplier_id']) ? (int)$data['supplier_id'] : null,
            'agent_id' => isset($data['agent_id']) ? (int)$data['agent_id'] : null,
            'account_id' => isset($data['account_id']) ? (int)$data['account_id'] : (isset($data['payment_account_id']) ? (int)$data['payment_account_id'] : null),
            'currency_id' => isset($data['currency_id']) ? (int)$data['currency_id'] : (isset($data['sale_currency_id']) ? (int)$data['sale_currency_id'] : null),
            'sale_currency_id' => isset($data['sale_currency_id']) ? (int)$data['sale_currency_id'] : (isset($data['currency_id']) ? (int)$data['currency_id'] : null),
            'purchase_currency_id' => isset($data['purchase_currency_id']) ? (int)$data['purchase_currency_id'] : (isset($data['pur_currency_id']) ? (int)$data['pur_currency_id'] : (isset($data['currency_id']) ? (int)$data['currency_id'] : null)),
            'exchange_rate' => (float)($data['exchange_rate'] ?? 1),
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'paid_amount' => $paidAmount,
            'sale_total_amount' => $saleTotal,
            'purchase_total_amount' => $purchaseTotal,
            'total_amount' => $saleTotal,
            'net_amount' => $netAmount,
            'remaining_amount' => max(0, $netAmount - $paidAmount),
            'transaction_status' => $data['transaction_status'] ?? $data['invoice_status'] ?? 'draft',
            'delivery_type' => $data['delivery_type'] ?? 'draft',
            'description' => trim((string)($data['description'] ?? '')),
            'operation_date' => normalize_datetime_db($data['operation_date'] ?? null),
            'source_number' => $data['source_number'] ?? null,
            'record_purchase' => isset($data['record_purchase']) ? (string)$data['record_purchase'] : '1',

            // â€” ط­ظ‚ظˆظ„ ط¬ط¯ظٹط¯ط© ظ„ظ„طھط­ظ‚ظ‚ ظپظ‚ط· (ظ„ط§ طھط¤ط«ط± ط¹ظ„ظ‰ ط§ظ„ظˆط§ط¬ظ‡ط© ط§ظ„ظ‚ط¯ظٹظ…ط©) â€”
            'expense_account_id'      => isset($data['expense_account_id']) ? (int)$data['expense_account_id'] : null,
            'voucher_date'            => $data['voucher_date'] ?? null,
            'reference_number'        => $data['reference_number'] ?? null,
            'cost_center_id'          => isset($data['cost_center_id']) ? (int)$data['cost_center_id'] : null,
            'budget_id'               => isset($data['budget_id']) ? (int)$data['budget_id'] : null,
            'idempotency_key'         => $data['idempotency_key'] ?? $data['request_id'] ?? null,
        ];

        return $normalized;
    }

    /**
     * طھظ†ظپظٹط° ط¹ظ…ظ„ظٹط© ظ…ط§ظ„ظٹط© ط¨ط´ظƒظ„ ط°ط±ظٹ.
     * â€” [ط¥طµظ„ط§ط­ ط¯ط§ط®ظ„ظٹ] ط§ظ„ط¢ظ† ظٹط¯ط¹ظ… Nested Calls ط¨ط´ظƒظ„ طµط­ظٹط­.
     */
    public function executeAtomically(callable $callback)
    {
        $spName = $this->safeBegin();

        try {
            $result = $callback();
            $this->safeEnd($spName, true);
            return $result;
        } catch (Throwable $e) {
            $this->safeEnd($spName, false);
            throw $e;
        }
    }

    // =================================================================
    // آ§آ§  ط¹ظ…ظ„ظٹط§طھ ط§ظ„ظپظˆط§طھظٹط±
    // =================================================================

    /**
     * âœ… [طھط­ط¯ظٹط« ط£ظ…ط§ظ†] â€” ط§ظ„ط¢ظ† ظ…ط¹:
     *   آ· ظپط­طµ Idempotency (ط®ظپظٹظپ ط¹ط¨ط± (category, source_type, source_id) …8366 tokens truncated…h(PDO::FETCH_ASSOC);
            if (!$supplier) {
                $this->safeEnd($sp, false);
                return null;
            }

            $branchId = $supplier['branch_id'] ? (int)$supplier['branch_id'] : null;
            $name = trim((string)($supplier['supplier_name'] ?: "ظ…ظˆط±ط¯ ط±ظ‚ظ… $supplierId"));

            $parentIdForSuppliers = 21;
            $stmtCode = $this->pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '21101' OR (account_type = 'liability' AND account_sub_type = 'supplier' AND parent_id IS NOT NULL) ORDER BY id ASC LIMIT 1");
            $stmtCode->execute();
            $pid = $stmtCode->fetchColumn();
            if ($pid) {
                $parentIdForSuppliers = (int)$pid;
            }

            $stmtMax = $this->pdo->prepare("
                SELECT COALESCE(MAX(CAST(REGEXP_REPLACE(account_code, '[^0-9]', '') AS UNSIGNED)), CAST(CONCAT(REGEXP_REPLACE((SELECT account_code FROM unified_accounts WHERE id = ?), '[^0-9]', ''), '00001') AS UNSIGNED))
                FROM unified_accounts WHERE parent_id = ?
            ");
            $stmtMax->execute([$parentIdForSuppliers, $parentIdForSuppliers]);
            $baseCode = (string)($stmtMax->fetchColumn() ?: '2110100001');
            $nextCode = (string)((int)(preg_replace('/[^0-9]/', '', $baseCode) ?: '2110100001') + 1);
            $nextCode = ltrim($nextCode, '0');

            $stmtIns = $this->pdo->prepare("
                INSERT INTO unified_accounts
                    (account_code, account_name_ar, account_type, account_sub_type, owner_type, normal_balance,
                     parent_id, branch_id, is_active, account_status, created_at)
                VALUES (?, ?, 'liability', 'supplier', 'supplier', 'credit',
                        ?, ?, 1, 'active', NOW())
            ");
            $stmtIns->execute([
                $nextCode,
                "ظ…ظˆط±ط¯ - $name",
                $parentIdForSuppliers,
                $branchId,
            ]);
            $accountId = (int)$this->pdo->lastInsertId();

            try {
                $stmtEns = $this->pdo->prepare("CALL sp_ensure_opening_balance(?, ?, ?, ?, 0, 0, 0)");
                $stmtEns->execute([$accountId, (int)($branchId ?: 1), 1, $this->userId]);
                $stmtEns->closeCursor();
            } catch (Throwable $e) {
            }

            $this->pdo->prepare("UPDATE suppliers SET account_id = ? WHERE id = ?")
                ->execute([$accountId, $supplierId]);

            $this->writeAudit('ensure_supplier_account', 'unified_accounts', $accountId, [
                'supplier_id' => $supplierId,
            ]);

            $this->safeEnd($sp, true);
            return $accountId;
        } catch (Throwable $e) {
            $this->safeEnd($sp, false);
            error_log("FinanceService::ensureSupplierAccount($supplierId) failed: " . $e->getMessage());
            $fallback = $this->fallbackBranchPayablesAccount();
            self::$partyAccountCache["supplier:$supplierId"] = $fallback;
            return $fallback;
        }
    }

    /**
     * ط¥ط±ط¬ط§ط¹ ط£ظˆ ط¥ظ†ط´ط§ط، ط¹ظ…ظٹظ„ ط§ظپطھط±ط§ط¶ظٹ ظ„ط¹ظ…ظ„ظٹط§طھ ط§ظ„ظ…ط¨ظٹط¹ط§طھ ط§ظ„ظ†ظ‚ط¯ظٹط© ط§ظ„طھظٹ ظ„ط§ ظٹظڈط­ط¯ط¯ ظپظٹظ‡ط§ ط¹ظ…ظٹظ„ ظ…ط­ط¯ط¯.
     * ظٹطھظ… ط¥ظ†ط´ط§ط¤ظ‡ طھظ„ظ‚ط§ط¦ظٹط§ظ‹ ط£ظˆظ„ ظ…ط±ط© ظ…ط¹ ط­ط³ط§ط¨ ظ…ط§ظ„ظٹ ظ…ط±طھط¨ط·.
     */
    public function getOrCreateDefaultCashCustomer(?int $branchId = null): int
    {
        static $cachedCustomerId = null;
        if ($cachedCustomerId !== null) {
            return $cachedCustomerId;
        }

        $sp = $this->safeBegin();
        try {
            $stmt = $this->pdo->prepare("
                SELECT id FROM customers
                WHERE (full_name = ? OR full_name LIKE 'ظ…ط¨ظٹط¹ط§طھ ظ†ظ‚ط¯ظٹط©%' OR full_name LIKE '%CASH%')
                  AND deleted_at IS NULL
                ORDER BY id ASC LIMIT 1
            ");
            $stmt->execute(['ظ…ط¨ظٹط¹ط§طھ ظ†ظ‚ط¯ظٹط© ط¹ط§ظ…']);
            $id = $stmt->fetchColumn();

            if ($id) {
                $id = (int)$id;
                $this->safeEnd($sp, true);
                $cachedCustomerId = $id;
                return $id;
            }

            $branchForInsert = (int)($branchId ?: 1);
            $stmtIns = $this->pdo->prepare("
                INSERT INTO customers
                    (full_name, phone, address, created_at, branch_id, status)
                VALUES (?, ?, ?, NOW(), ?, 'active')
            ");
            $stmtIns->execute([
                'ظ…ط¨ظٹط¹ط§طھ ظ†ظ‚ط¯ظٹط© ط¹ط§ظ…',
                '-',
                '-',
                $branchForInsert,
            ]);
            $id = (int)$this->pdo->lastInsertId();

            $this->ensureCustomerAccount($id);

            $this->writeAudit('ensure_default_cash_customer', 'customers', $id, [
                'branch_id' => $branchForInsert,
            ]);

            $this->safeEnd($sp, true);
            $cachedCustomerId = $id;
            return $id;
        } catch (Throwable $e) {
            $this->safeEnd($sp, false);
            error_log("FinanceService::getOrCreateDefaultCashCustomer failed: " . $e->getMessage());
            $stmt = $this->pdo->query("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1");
            $fallback = (int)($stmt->fetchColumn() ?: 1);
            $cachedCustomerId = $fallback;
            return $fallback;
        }
    }

    private function fallbackBranchReceivablesAccount(): ?int
    {
        try {
            $stmt = $this->pdo->query("
                SELECT id FROM unified_accounts
                WHERE (account_sub_type = 'customer' OR account_code LIKE '11201%')
                  AND is_active = 1
                ORDER BY parent_id IS NOT NULL DESC, id ASC LIMIT 1
            ");
            $id = $stmt->fetchColumn();
            return $id ? (int)$id : 10;
        } catch (Throwable $e) {
            return 10;
        }
    }

    private function fallbackBranchPayablesAccount(): ?int
    {
        try {
            $stmt = $this->pdo->query("
                SELECT id FROM unified_accounts
                WHERE (account_sub_type = 'supplier' OR account_code LIKE '21101%')
                  AND is_active = 1
                ORDER BY parent_id IS NOT NULL DESC, id ASC LIMIT 1
            ");
            $id = $stmt->fetchColumn();
            return $id ? (int)$id : 21;
        } catch (Throwable $e) {
            return 21;
        }
    }

    // =================================================================
    // آ§آ§  ط³ظ†ط¯ط§طھ ط§ظ„ظ…طµط±ظˆظپط§طھ ط§ظ„ظ…ط±ظƒط²ظٹط© (Expense Vouchers)
    // =================================================================

    public function createExpenseVoucherDraft(array $data): int
    {
        $data = $this->normalizeFinancialPayload($data);

        $this->assertUserCan('create_expense_voucher', 'ط¥ظ†ط´ط§ط، ط³ظ†ط¯ ظ…طµط±ظˆظپ');
        $this->assertFiscalPeriodOpen($data['voucher_date'] ?: $data['operation_date']);

        if (empty($data['expense_account_id'])) {
            throw new Exception('ط­ط³ط§ط¨ ط§ظ„ظ…طµط±ظˆظپ ظ…ط·ظ„ظˆط¨.');
        }
        if (empty($data['account_id'])) {
            throw new Exception('ط­ط³ط§ط¨ ط§ظ„طµظ†ط¯ظˆظ‚/ط§ظ„ط¨ظ†ظƒ ظ…ط·ظ„ظˆط¨.');
        }
        if (empty($data['paid_amount']) || (float)$data['paid_amount'] <= 0) {
            throw new Exception('ظ…ط¨ظ„ط؛ ط§ظ„ظ…طµط±ظˆظپ ط؛ظٹط± طµط§ظ„ط­.');
        }

        $this->assertAccountUsable((int)$data['account_id'], 'ط§ظ„طµظ†ط¯ظˆظ‚/ط§ظ„ط¨ظ†ظƒ');
        $this->assertAccountUsable((int)$data['expense_account_id'], 'ط§ظ„ظ…طµط±ظˆظپ');

        $stmt = $this->pdo->prepare(
            "CALL sp_create_expense_voucher(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, @v_id, @v_num)"
        );
        $stmt->execute([
            $data['branch_id'],
            (int)$data['expense_account_id'],
            (int)$data['account_id'],
            (float)$data['paid_amount'],
            (int)$data['currency_id'],
            (float)($data['equivalent_amount'] ?? 0),
            !empty($data['voucher_date']) ? $data['voucher_date'] : date('Y-m-d'),
            $data['description'] ?? null,
            $data['reference_number'] ?? null,
            !empty($data['cost_center_id']) ? (int)$data['cost_center_id'] : null,
            !empty($data['supplier_id']) ? (int)$data['supplier_id'] : null,
            !empty($data['budget_id']) ? (int)$data['budget_id'] : null,
            $this->userId,
        ]);
        $stmt->closeCursor();

        $voucherId = (int)$this->pdo->query("SELECT @v_id")->fetchColumn();

        $this->writeAudit('create_expense_voucher_draft', 'expense_voucher', $voucherId, [
            'expense_account_id' => $data['expense_account_id'],
            'account_id'         => $data['account_id'],
            'amount'             => $data['paid_amount'],
        ]);

        return $voucherId;
    }

    public function postExpenseVoucher(int $voucherId): void
    {
        if ($voucherId <= 0) {
            throw new Exception('ط±ظ‚ظ… ط³ظ†ط¯ ظ…طµط±ظˆظپ ط؛ظٹط± طµط§ظ„ط­.');
        }

        $this->assertUserCan('post_expense_voucher', 'طھط±ط­ظٹظ„ ط³ظ†ط¯ ظ…طµط±ظˆظپ');

        $stmt = $this->pdo->prepare("CALL sp_post_expense_voucher(?, ?)");
        $stmt->execute([$voucherId, $this->userId]);
        $stmt->closeCursor();

        $this->writeAudit('post_expense_voucher', 'expense_voucher', $voucherId);
    }

    public function processExpenseApproval(int $voucherId, int $level, bool $approved, ?string $comment = null): void
    {
        if ($voucherId <= 0) {
            throw new Exception('ط±ظ‚ظ… ط³ظ†ط¯ ظ…طµط±ظˆظپ ط؛ظٹط± طµط§ظ„ط­.');
        }

        $this->assertUserCan('approve_expense_voucher', 'ظ…ظˆط§ظپظ‚ط© ط¹ظ„ظ‰ ط³ظ†ط¯ ظ…طµط±ظˆظپ');

        $stmt = $this->pdo->prepare("CALL sp_process_expense_approval(?, ?, ?, ?, ?)");
        $stmt->execute([
            $voucherId,
            $this->userId,
            $level,
            $approved ? 1 : 0,
            $comment,
        ]);
        $stmt->closeCursor();

        $this->writeAudit('expense_voucher_approval', 'expense_voucher', $voucherId, [
            'level'    => $level,
            'approved' => $approved,
            'comment'  => $comment,
        ]);
    }

    // =================================================================
    // آ§آ§  ط¯ظˆط§ظ„ ظ…ط³ط§ط¹ط¯ط© ط®ط§طµط© (Validations) ظ„ظ„ظ†ط³ط®ط© ط§ظ„ط«ط§ظ†ظٹط©
    // =================================================================

    /**
     * ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† ط£ظ† ط§ظ„ط­ط³ط§ط¨ ط§ظ„ظ…ط§ظ„ظٹ ظ‚ط§ط¨ظ„ ظ„ظ„ط§ط³طھط®ط¯ط§ظ… (ظ†ط´ط· ظˆط؛ظٹط± ظ…ط¬ظ…ط¯ â€” ط¥ظ† طھظˆظپط±طھ ط¹ظ…ظˆط¯ ط§ظ„طھط¬ظ…ظٹط¯).
     */
    private function assertAccountUsable(int $accountId, string $label): void
    {
        static $cache = [];
        if (isset($cache[$accountId])) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT id, is_active, account_status, deleted_at
                FROM unified_accounts WHERE id = ? LIMIT 1
            ");
            $stmt->execute([$accountId]);
            $acc = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$acc) {
                throw new Exception("ط­ط³ط§ط¨ $label ط±ظ‚ظ… $accountId ط؛ظٹط± ظ…ظˆط¬ظˆط¯ ظپظٹ ط´ط¬ط±ط© ط§ظ„ط­ط³ط§ط¨ط§طھ.");
            }
            if (!empty($acc['deleted_at'])) {
                throw new Exception("ط­ط³ط§ط¨ $label ط±ظ‚ظ… $accountId ظ…ط­ط°ظˆظپ (soft deleted).");
            }
            $status = (string)($acc['account_status'] ?? 'active');
            if ($status !== 'active' && $status !== '' && $status !== '0') {
                throw new Exception("ط­ط³ط§ط¨ $label ط±ظ‚ظ… $accountId ط¨ط­ط§ظ„ط© آ«{$status}آ» â€” ط؛ظٹط± ظ†ط´ط· ط­ط§ظ„ظٹط§ظ‹.");
            }

            // طھط­ظ‚ظ‚ ظ…ظ† ط¹ط¯ظ… طھط¬ظ…ظٹط¯ ط§ظ„ط­ط³ط§ط¨ ظپظٹ ط¬ط¯ظˆظ„ ط§ظ„ط£ط±طµط¯ط© (ط¥ظ† طھظˆظپط± ط¨ظٹط§ظ†ط§طھ طھط¬ظ…ظٹط¯)
            try {
                $frzStmt = $this->pdo->prepare("
                    SELECT is_frozen FROM account_balances_unified
                    WHERE account_id = ? ORDER BY id ASC LIMIT 1
                ");
                $frzStmt->execute([$accountId]);
                $frozen = $frzStmt->fetchColumn();
                if ($frozen == 1) {
                    throw new Exception("ط­ط³ط§ط¨ $label ط±ظ‚ظ… $accountId ظ…ظڈط¬ظ…ظ‘ط¯ ط­ط§ظ„ظٹط§ظ‹.");
                }
            } catch (Throwable $e) {
                if (mb_strpos($e->getMessage(), 'ظ…ظڈط¬ظ…ظ‘ط¯') !== false) {
                    throw $e;
                }
            }
            $cache[$accountId] = true;
        } catch (Throwable $e) {
            if (
                mb_strpos($e->getMessage(), 'ط؛ظٹط± ظ…ظˆط¬ظˆط¯') !== false
                || mb_strpos($e->getMessage(), 'ظ…ط­ط°ظˆظپ') !== false
                || mb_strpos($e->getMessage(), 'ط؛ظٹط± ظ†ط´ط·') !== false
                || mb_strpos($e->getMessage(), 'ظ…ظڈط¬ظ…ظ‘ط¯') !== false
            ) {
                throw $e;
            }
            // ط£ظٹ ط®ط·ط£ ط¢ط®ط± (ظ…ط«ظ„ ط¹ظ…ظˆط¯ ظ…ظپظ‚ظˆط¯ ظپظٹ ط¥طµط¯ط§ط± ظ‚ط¯ظٹظ…) â†’ طھط¬ط§ظ‡ظ„ ط¨ط³ظ„ط§ظ…ط©
        }
    }
}

