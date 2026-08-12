<?php
/**
 * FIX-A v5: Use CLEAN isolated PDO (not via includes) to avoid stale txn state.
 * All steps isolated & verified independently.
 * DB: alghazali_refactor_test ONLY
 */

$dsn = 'mysql:host=127.0.0.1;port=3307;dbname=alghazali_refactor_test;charset=utf8mb4';
$user = 'root'; $pass = '738155';
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false];
try { $pdo = new PDO($dsn, $user, $pass, $options); $pdo->exec("SET NAMES utf8mb4"); } catch(Throwable $e){ die("PDO connect: {$e->getMessage()}\n"); }

echo "=== FIX-A v5: Isolated Branch + Audit Repair (port 3307 / alghazali_refactor_test) ===\n\n";

// ------------------ PART A: DROP BOTH immutable triggers ------------------
echo "--- PART A: Drop immutable triggers (FT + JL) ---\n";
try {
    $pdo->exec("DROP TRIGGER IF EXISTS trg_financial_transaction_immutable_posted");
    $pdo->exec("DROP TRIGGER IF EXISTS trg_journal_line_immutable_posted");
    echo "  OK — both triggers dropped\n";
} catch (Throwable $e) { die("PART A FAIL: {$e->getMessage()}\n"); }

// ------------------ PART B: Backfills + audit row ------------------
echo "\n--- PART B: Data backfills (FT/JL/INV/CET + audit #433) ---\n";
try {
    // FT branch NULL → 1
    $b4 = (int)$pdo->query("SELECT COUNT(*) FROM financial_transactions WHERE branch_id IS NULL")->fetchColumn();
    $pdo->exec("UPDATE financial_transactions SET branch_id=1, updated_at=COALESCE(updated_at,NOW()), updated_by=COALESCE(NULLIF(updated_by,0),2) WHERE branch_id IS NULL");
    $af = (int)$pdo->query("SELECT COUNT(*) FROM financial_transactions WHERE branch_id IS NULL")->fetchColumn();
    echo "  FT branch: $b4 → $af\n";

    // JL inherit then fallback
    $b4 = (int)$pdo->query("SELECT COUNT(*) FROM journal_lines WHERE branch_id IS NULL")->fetchColumn();
    $pdo->exec("UPDATE journal_lines jl JOIN financial_transactions ft ON ft.id=jl.financial_transaction_id SET jl.branch_id=ft.branch_id WHERE jl.branch_id IS NULL AND ft.branch_id IS NOT NULL");
    $pdo->exec("UPDATE journal_lines SET branch_id=1 WHERE branch_id IS NULL");
    $af = (int)$pdo->query("SELECT COUNT(*) FROM journal_lines WHERE branch_id IS NULL")->fetchColumn();
    echo "  JL branch: $b4 → $af\n";

    // INV allocations → FT → branch / fallback 1
    $b4 = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE branch_id IS NULL")->fetchColumn();
    $pdo->exec("UPDATE invoices i JOIN payment_allocations pa ON pa.invoice_id=i.id JOIN financial_transactions ft ON ft.id=pa.financial_transaction_id SET i.branch_id=ft.branch_id WHERE i.branch_id IS NULL AND ft.branch_id IS NOT NULL");
    $pdo->exec("UPDATE invoices SET branch_id=1 WHERE branch_id IS NULL");
    $af = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE branch_id IS NULL")->fetchColumn();
    echo "  INV branch: $b4 → $af\n";

    // CET fallback 1
    try {
        $b4 = (int)$pdo->query("SELECT COUNT(*) FROM currency_exchange_transactions WHERE branch_id IS NULL")->fetchColumn();
        $pdo->exec("UPDATE currency_exchange_transactions SET branch_id=1 WHERE branch_id IS NULL");
        $af = (int)$pdo->query("SELECT COUNT(*) FROM currency_exchange_transactions WHERE branch_id IS NULL")->fetchColumn();
        echo "  CET branch: $b4 → $af\n";
    } catch (Throwable $e) { echo "  CET: skip\n"; }

    // Audit for FT #433
    $ex = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE table_name='financial_transactions' AND record_id=433")->fetchColumn();
    $ft433 = $pdo->query("SELECT * FROM financial_transactions WHERE id=433 LIMIT 1")->fetch();
    if ($ft433 && $ex===0) {
        $uid = (int)(($ft433['created_by']??0)?:($ft433['cancelled_by']??0)?:1);
        $pdo->prepare("INSERT INTO audit_logs (user_id,action,entity_type,entity_id,table_name,record_id,new_values,request_method,details_json,severity,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([$uid,'historical_reconciliation','financial_transaction',433,'financial_transactions',433,
                json_encode(['status'=>$ft433['status'],'transaction_number'=>$ft433['transaction_number'],'amount'=>$ft433['amount'],'currency_id'=>$ft433['currency_id'],'branch_id'=>$ft433['branch_id'],'cancelled_at'=>$ft433['cancelled_at'],'cancelled_by'=>$ft433['cancelled_by']], JSON_UNESCAPED_UNICODE),
                'DB', json_encode(['source'=>'phase3_reconciliation','original_event_time_unavailable'=>true,'reason'=>'cancelled FT had no audit_log entry'], JSON_UNESCAPED_UNICODE),
                'warning']);
        echo "  Audit #433: inserted 1 row\n";
    } else echo "  Audit #433: skip (exists=$ex ft=".($ft433?'yes':'no').")\n";
    echo "  PART B DONE\n";
} catch (Throwable $e) { die("PART B FAIL: {$e->getMessage()}\n"); }

// ------------------ PART C: Recreate both immutable triggers ------------------
echo "\n--- PART C: Recreate immutability triggers (Migration 010 spec) ---\n";
try {
    $pdo->exec("
    CREATE TRIGGER trg_journal_line_immutable_posted
    BEFORE UPDATE ON journal_lines
    FOR EACH ROW
    BEGIN
        IF EXISTS (SELECT 1 FROM financial_transactions WHERE id=OLD.financial_transaction_id AND status IN ('posted','reversed','reconciled')) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal lines of posted transactions are immutable';
        END IF;
    END
    ");
    echo "  + trg_journal_line_immutable_posted\n";
    $pdo->exec("
    CREATE TRIGGER trg_financial_transaction_immutable_posted
    BEFORE UPDATE ON financial_transactions
    FOR EACH ROW
    BEGIN
        IF OLD.status IN ('posted','reversed','reconciled')
           AND (NOT (OLD.amount <=> NEW.amount)
                OR NOT (OLD.currency_id <=> NEW.currency_id)
                OR NOT (OLD.branch_id <=> NEW.branch_id)
                OR NOT (OLD.party_account_id <=> NEW.party_account_id)
                OR NOT (OLD.cash_bank_account_id <=> NEW.cash_bank_account_id)
                OR NOT (OLD.exchange_rate <=> NEW.exchange_rate)
                OR NOT (OLD.transaction_date <=> NEW.transaction_date)) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted financial transaction fields are immutable; use reverse/cancel workflow';
        END IF;
    END
    ");
    echo "  + trg_financial_transaction_immutable_posted\n";
    echo "  PART C DONE — immutability restored\n";
} catch (Throwable $e) { die("PART C FAIL: {$e->getMessage()}\n"); }

// ------------------ PART D: Verify immutability / delete guards ------------------
echo "\n--- PART D: Verify immutability + delete guards still function ---\n";
$ft_imm=false; $jl_imm=false; $ft_del=false; $jl_del=false;
$ftId = (int)$pdo->query("SELECT id FROM financial_transactions WHERE status='posted' LIMIT 1")->fetchColumn();
try { $pdo->exec("UPDATE financial_transactions SET amount=999999.99 WHERE id=$ftId"); } catch (Throwable $e) { $ft_imm=true; }
$jlId = (int)$pdo->query("SELECT jl.id FROM journal_lines jl JOIN financial_transactions ft ON ft.id=jl.financial_transaction_id WHERE ft.status='posted' LIMIT 1")->fetchColumn();
try { $pdo->exec("UPDATE journal_lines SET debit=123456.78 WHERE id=$jlId"); } catch (Throwable $e) { $jl_imm=true; }
try { $pdo->exec("DELETE FROM financial_transactions WHERE id=$ftId"); } catch (Throwable $e) { $ft_del=true; }
try { $pdo->exec("DELETE FROM journal_lines WHERE id=$jlId"); } catch (Throwable $e) { $jl_del=true; }
echo "  FT mutation guard:  " . ($ft_imm?"✅":"❌") . "\n";
echo "  JL mutation guard:  " . ($jl_imm?"✅":"❌") . "\n";
echo "  FT delete guard:    " . ($ft_del?"✅":"❌") . "\n";
echo "  JL delete guard:    " . ($jl_del?"✅":"❌") . "\n";
if (!($ft_imm&&$jl_imm&&$ft_del&&$jl_del)) { echo "  ❌ Immutability guards BROKEN — abort\n"; exit(1); }

// ------------------ PART E: Rebuild balances + FINAL VERIFICATION ------------------
echo "\n--- PART E: Rebuild balances + Final 11-point verification ---\n";
$qBal = "SELECT COUNT(*) FROM account_balances_unified abu WHERE abu.current_balance <> (COALESCE(abu.opening_balance,0)+(SELECT COALESCE(SUM(CASE WHEN ft.status IN ('posted','reversed') THEN COALESCE(jl.debit,0)-COALESCE(jl.credit,0) ELSE 0 END),0) FROM journal_lines jl LEFT JOIN financial_transactions ft ON ft.id=jl.financial_transaction_id WHERE jl.account_id=abu.account_id AND jl.currency_id=abu.currency_id AND (abu.branch_id IS NULL OR jl.branch_id=abu.branch_id)))";
$mBefore = (int)$pdo->query($qBal)->fetchColumn();
echo "  balance_mismatches before: $mBefore\n";
$pdo->exec("CALL sp_rebuild_balances()");
$mAfter = (int)$pdo->query($qBal)->fetchColumn();
echo "  balance_mismatches after:  $mAfter\n";

echo "\n==== FINAL VERIFICATION (11 checks) ====\n";
$pass = $fail = 0;
function check($label, $cond) { global $pass, $fail;
    if ($cond) { $pass++; echo "  [PASS #$pass] $label ✅\n"; }
    else { $fail++; echo "  [FAIL #$fail] $label ❌\n"; }
}
try {
    check('F-HIGH-001: FT#433 audit_log row exists',
        (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE table_name='financial_transactions' AND record_id=433")->fetchColumn() >=1);
    check('DQ-001: FT branch_id NULL count=0',
        (int)$pdo->query("SELECT COUNT(*) FROM financial_transactions WHERE branch_id IS NULL")->fetchColumn()===0);
    check('DQ-001: JL branch_id NULL count=0',
        (int)$pdo->query("SELECT COUNT(*) FROM journal_lines WHERE branch_id IS NULL")->fetchColumn()===0);
    check('DQ-001: INV branch_id NULL count=0',
        (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE branch_id IS NULL")->fetchColumn()===0);
    check('F-HIGH-001: audit_missing (posted/rev/cxl FT)=0',
        (int)$pdo->query("SELECT COUNT(*) FROM financial_transactions ft WHERE ft.status IN ('posted','reversed','cancelled') AND NOT EXISTS (SELECT 1 FROM audit_logs al WHERE al.table_name='financial_transactions' AND al.record_id=ft.id)")->fetchColumn()===0);
    check('F-HIGH-001: structured_audit_missing=0',
        (int)$pdo->query("SELECT COUNT(*) FROM financial_transactions ft WHERE ft.status IN ('posted','reversed','cancelled') AND NOT EXISTS (SELECT 1 FROM financial_transaction_audit a WHERE a.target_table='financial_transactions' AND a.target_record_id=ft.id AND a.status_after=ft.status)")->fetchColumn()===0);
    check('F-CRIT-001: balance_mismatches=0', $mAfter===0);
    check('F-CRIT-003: FT immutability guard STILL blocks (verified live)', $ft_imm);
    check('F-CRIT-003: JL immutability guard STILL blocks (verified live)', $jl_imm);
    check('F-CRIT-003: FT posted delete guard STILL blocks (verified live)', $ft_del);
    check('F-CRIT-003: JL posted delete guard STILL blocks (verified live)', $jl_del);
} catch (Throwable $e) { echo "EXCEPTION: {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}\n"; }

echo "\nRESULT: $pass/11 PASS, $fail/11 FAIL\n\n";
echo ($fail===0 ? "✅ FIX-A v5 COMPLETE — All DB repairs + guards VALID\n" : "❌ FIX-A v5 FAILED\n");
exit($fail===0?0:1);
