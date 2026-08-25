<?php
declare(strict_types=1);

namespace App\Includes;

use App\Classes\Database;
use PDO;

final class AgentService
{
    private AuditLogger $audit;
    private NotificationService $notifications;

    public function __construct(private Database $database)
    {
        $this->audit = new AuditLogger($database);
        $this->notifications = new NotificationService($database);
    }

    /** @return list<array<string, mixed>> */
    public function walletsFor(array $actor): array
    {
        if ($actor['agent_id'] === null) {
            Response::error('هذا المورد مخصص لحسابات الوكلاء فقط.', 'FORBIDDEN', 403);
        }
        return $this->wallets((int) $actor['agent_id']);
    }

    /** @return list<array<string, mixed>> */
    public function transactionsFor(array $actor): array
    {
        if ($actor['agent_id'] === null) {
            Response::error('هذا المورد مخصص لحسابات الوكلاء فقط.', 'FORBIDDEN', 403);
        }
        return $this->transactions((int) $actor['agent_id']);
    }

    /** @return list<array<string, mixed>> */
    public function commissionsFor(array $actor): array
    {
        if ($actor['agent_id'] === null) {
            Response::error('هذا المورد مخصص لحسابات الوكلاء فقط.', 'FORBIDDEN', 403);
        }
        $statement = $this->database->pdo()->prepare(
            'SELECT ac.id, ac.amount, ac.commission_type, ac.rate_value, ac.status, ac.created_at, c.code AS currency_code, c.symbol_ar AS currency_symbol, b.booking_number
             FROM agent_commissions ac INNER JOIN currencies c ON c.id = ac.currency_id INNER JOIN bookings b ON b.id = ac.booking_id
             WHERE ac.agent_id = :agent_id ORDER BY ac.created_at DESC'
        );
        $statement->execute(['agent_id' => $actor['agent_id']]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed> */
    public function creditWallet(array $actor, int $agentId, array $input): array
    {
        if (!in_array('manage_agents', $actor['permissions'], true) && !in_array('super_admin', $actor['roles'], true)) {
            Response::error('لا تملك صلاحية تعديل رصيد الوكلاء.', 'FORBIDDEN', 403);
        }
        $currencyId = filter_var($input['currency_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $amount = filter_var($input['amount'] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0.01]]);
        $reason = Security::cleanText($input['reason'] ?? null, 500);
        if ($currencyId === false || $amount === false) {
            Response::error('العملة والمبلغ مطلوبان وبقيم صحيحة.', 'VALIDATION_ERROR', 422);
        }
        return $this->database->transaction(function (PDO $pdo) use ($actor, $agentId, $currencyId, $amount, $reason): array {
            $agent = $this->one($pdo, 'SELECT a.id, a.company_id, a.user_id FROM agents a WHERE a.id = :id FOR UPDATE', ['id' => $agentId]);
            if ($agent === null) {
                Response::error('الوكيل المطلوب غير موجود.', 'NOT_FOUND', 404);
            }
            if (!in_array('super_admin', $actor['roles'], true) && (int) ($actor['company_id'] ?? 0) !== (int) $agent['company_id']) {
                Response::error('لا يمكن تعديل حساب وكيل تابع لشركة أخرى.', 'FORBIDDEN', 403);
            }
            $wallet = $this->one($pdo, 'SELECT * FROM agent_wallets WHERE agent_id = :agent_id AND currency_id = :currency_id FOR UPDATE', ['agent_id' => $agentId, 'currency_id' => $currencyId]);
            if ($wallet === null) {
                $pdo->prepare('INSERT INTO agent_wallets (agent_id, currency_id, balance) VALUES (:agent_id, :currency_id, 0)')->execute(['agent_id' => $agentId, 'currency_id' => $currencyId]);
                $wallet = $this->one($pdo, 'SELECT * FROM agent_wallets WHERE agent_id = :agent_id AND currency_id = :currency_id FOR UPDATE', ['agent_id' => $agentId, 'currency_id' => $currencyId]);
            }
            $before = (float) $wallet['balance'];
            $after = number_format($before + $amount, 2, '.', '');
            $pdo->prepare('UPDATE agent_wallets SET balance = :balance WHERE id = :id')->execute(['balance' => $after, 'id' => $wallet['id']]);
            $pdo->prepare('INSERT INTO agent_wallet_transactions (agent_wallet_id, transaction_type, credit_amount, balance_before, balance_after, debt_before, debt_after, performed_by_user_id, reason) VALUES (:wallet_id, \'top_up\', :credit_amount, :balance_before, :balance_after, :debt_before, :debt_after, :performed_by_user_id, :reason)')->execute([
                'wallet_id' => $wallet['id'], 'credit_amount' => $amount, 'balance_before' => $before, 'balance_after' => $after, 'debt_before' => $wallet['used_debt'], 'debt_after' => $wallet['used_debt'], 'performed_by_user_id' => $actor['id'], 'reason' => $reason,
            ]);
            $this->notifications->send((int) $agent['user_id'], (int) $agent['company_id'], 'wallet_top_up', 'تمت إضافة رصيد إلى الحساب', "تمت إضافة رصيد إلى محفظتك. السبب: {$reason}", 'agent_wallet', (int) $wallet['id']);
            $this->audit->log((int) $actor['id'], (int) $agent['company_id'], 'agent_wallet_credited', 'agent_wallet', (int) $wallet['id'], ['balance' => $before], ['balance' => $after, 'reason' => $reason]);
            return $this->walletById($pdo, (int) $wallet['id']);
        });
    }

    /** @return array<string, mixed> */
    public function updateFinancialSettings(array $actor, int $agentId, array $input): array
    {
        if (!in_array('manage_agents', $actor['permissions'], true) && !in_array('super_admin', $actor['roles'], true)) {
            Response::error('لا تملك صلاحية تعديل إعدادات الوكيل المالية.', 'FORBIDDEN', 403);
        }
        $currencyId = filter_var($input['currency_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $creditLimit = filter_var($input['credit_limit'] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0]]);
        $minimumBalance = filter_var($input['minimum_balance'] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0]]);
        $creditEnabled = filter_var($input['credit_enabled'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $blockAtMinimum = filter_var($input['block_at_minimum_balance'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $status = (string) ($input['status'] ?? '');
        if ($currencyId === false || $creditLimit === false || $minimumBalance === false || $creditEnabled === null || $blockAtMinimum === null || !in_array($status, ['active', 'financially_blocked', 'suspended'], true)) {
            Response::error('إعدادات الوكيل المالية غير صالحة.', 'VALIDATION_ERROR', 422);
        }
        return $this->database->transaction(function (PDO $pdo) use ($actor, $agentId, $currencyId, $creditLimit, $minimumBalance, $creditEnabled, $blockAtMinimum, $status): array {
            $agent = $this->one($pdo, 'SELECT * FROM agents WHERE id = :id FOR UPDATE', ['id' => $agentId]);
            if ($agent === null) {
                Response::error('الوكيل المطلوب غير موجود.', 'NOT_FOUND', 404);
            }
            if (!in_array('super_admin', $actor['roles'], true) && (int) ($actor['company_id'] ?? 0) !== (int) $agent['company_id']) {
                Response::error('لا يمكن تعديل وكيل تابع لشركة أخرى.', 'FORBIDDEN', 403);
            }
            $wallet = $this->one($pdo, 'SELECT * FROM agent_wallets WHERE agent_id = :agent_id AND currency_id = :currency_id FOR UPDATE', ['agent_id' => $agentId, 'currency_id' => $currencyId]);
            if ($wallet === null) {
                $pdo->prepare('INSERT INTO agent_wallets (agent_id, currency_id, balance, credit_limit, minimum_balance) VALUES (:agent_id, :currency_id, 0, :credit_limit, :minimum_balance)')->execute(['agent_id' => $agentId, 'currency_id' => $currencyId, 'credit_limit' => $creditLimit, 'minimum_balance' => $minimumBalance]);
                $wallet = $this->one($pdo, 'SELECT * FROM agent_wallets WHERE agent_id = :agent_id AND currency_id = :currency_id FOR UPDATE', ['agent_id' => $agentId, 'currency_id' => $currencyId]);
            }
            if ((float) $wallet['used_debt'] > $creditLimit) {
                Response::error('لا يمكن خفض الحد الائتماني إلى أقل من الدين المستخدم حاليًا.', 'VALIDATION_ERROR', 409);
            }
            $pdo->prepare('UPDATE agents SET status = :status, credit_enabled = :credit_enabled, block_at_minimum_balance = :block_at_minimum_balance WHERE id = :id')->execute(['status' => $status, 'credit_enabled' => $creditEnabled ? 1 : 0, 'block_at_minimum_balance' => $blockAtMinimum ? 1 : 0, 'id' => $agentId]);
            $pdo->prepare('UPDATE agent_wallets SET credit_limit = :credit_limit, minimum_balance = :minimum_balance WHERE id = :id')->execute(['credit_limit' => $creditLimit, 'minimum_balance' => $minimumBalance, 'id' => $wallet['id']]);
            $this->audit->log((int) $actor['id'], (int) $agent['company_id'], 'agent_financial_settings_updated', 'agent', $agentId, ['status' => $agent['status'], 'credit_enabled' => $agent['credit_enabled'], 'credit_limit' => $wallet['credit_limit']], ['status' => $status, 'credit_enabled' => $creditEnabled, 'credit_limit' => $creditLimit, 'minimum_balance' => $minimumBalance]);
            $this->notifications->send((int) $agent['user_id'], (int) $agent['company_id'], 'agent_financial_settings_updated', 'تم تحديث إعدادات حسابك', 'تم تعديل إعدادات الائتمان أو الحد المالي لحساب الوكيل.', 'agent', $agentId);
            return $this->walletById($pdo, (int) $wallet['id']);
        });
    }

    /** @return list<array<string, mixed>> */
    private function wallets(int $agentId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT w.id, w.balance, w.credit_limit, w.used_debt, w.minimum_balance, c.code AS currency_code, c.name_ar AS currency_name, c.symbol_ar AS currency_symbol,
                    GREATEST(0, w.credit_limit - w.used_debt) AS credit_available,
                    (w.balance + GREATEST(0, w.credit_limit - w.used_debt)) AS booking_available
             FROM agent_wallets w INNER JOIN currencies c ON c.id = w.currency_id WHERE w.agent_id = :agent_id ORDER BY c.code'
        );
        $statement->execute(['agent_id' => $agentId]);
        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    private function transactions(int $agentId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT tx.id, tx.transaction_type, tx.debit_amount, tx.credit_amount, tx.balance_before, tx.balance_after, tx.debt_before, tx.debt_after, tx.reason, tx.created_at,
                    c.code AS currency_code, c.symbol_ar AS currency_symbol, b.booking_number, u.full_name AS performed_by
             FROM agent_wallet_transactions tx INNER JOIN agent_wallets w ON w.id = tx.agent_wallet_id INNER JOIN currencies c ON c.id = w.currency_id
             LEFT JOIN bookings b ON b.id = tx.booking_id LEFT JOIN users u ON u.id = tx.performed_by_user_id
             WHERE w.agent_id = :agent_id ORDER BY tx.created_at DESC'
        );
        $statement->execute(['agent_id' => $agentId]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed> */
    private function walletById(PDO $pdo, int $walletId): array
    {
        $statement = $pdo->prepare('SELECT w.id, w.balance, w.credit_limit, w.used_debt, w.minimum_balance, c.code AS currency_code, c.symbol_ar AS currency_symbol FROM agent_wallets w INNER JOIN currencies c ON c.id = w.currency_id WHERE w.id = :id');
        $statement->execute(['id' => $walletId]);
        return $statement->fetch() ?: [];
    }

    /** @param array<string, mixed> $params @return array<string, mixed>|null */
    private function one(PDO $pdo, string $sql, array $params): ?array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $result = $statement->fetch();
        return is_array($result) ? $result : null;
    }
}
