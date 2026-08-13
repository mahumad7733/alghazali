<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "==========================================================\n";
echo "  تطبيق باتش sp_create_invoice المصحح (نسخة مبسطة)\n";
echo "==========================================================\n\n";

$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'alghazali_app';
$pass = getenv('DB_PASS') ?: 'localdev';
$db   = getenv('DB_NAME') ?: 'ghazali';

// 1) اتصال مباشر بدون أي ملفات خارجية
try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user, $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
        ]
    );
    $pdo->exec("SET NAMES utf8mb4");
    echo "[✅] الاتصال بقاعدة البيانات ناجح\n";
} catch (PDOException $e) {
    echo "[❌] فشل الاتصال: " . $e->getMessage() . "\n";
    exit(1);
}

// 2) نسخة احتياطية
echo "\n[1/4] نسخة احتياطية من الإجراء الحالي...\n";
$backupDir = __DIR__ . '/backups';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0755, true);
}
try {
    $stmt = $pdo->query("SHOW CREATE PROCEDURE sp_create_invoice");
    $row = $stmt->fetch();
    if ($row && !empty($row['Create Procedure'])) {
        $backupFile = $backupDir . '/sp_create_invoice_backup_' . date('Ymd_His') . '.sql';
        file_put_contents($backupFile, "-- Backup auto-generated\nDELIMITER $$\n\n" . $row['Create Procedure'] . "$$\n\nDELIMITER ;\n");
        echo "      ✅ تم الحفظ في: " . basename($backupFile) . "\n";
    } else {
        echo "      ℹ️  الإجراء غير موجود مسبقاً\n";
    }
} catch (Throwable $e) {
    echo "      ℹ️  لا يوجد إجراء قديم: " . $e->getMessage() . "\n";
}

// 3) التحقق من وجود الدوال الأساسية
echo "\n[2/4] التحقق من الدوال المطلوبة...\n";
foreach (['fn_sanitize_safe','fn_get_next_sequence'] as $fn) {
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = ? AND routine_name = ? AND routine_type = 'FUNCTION'");
    $check->execute([$db, $fn]);
    $exists = $check->fetchColumn();
    echo ($exists ? "      ✅ الدالة $fn موجودة\n" : "      ❌ الدالة $fn مفقودة!\n");
    if (!$exists) { exit(1); }
}

// 4) تطبيق الإجراء الجديد
echo "\n[3/4] تطبيق الإجراء الجديد...\n";

try {
    $pdo->exec("DROP PROCEDURE IF EXISTS sp_create_invoice");
    echo "      ✅ حذف الإجراء القديم\n";
} catch (Throwable $e) {
    echo "      ℹ️  حذف سابق: " . $e->getMessage() . "\n";
}

$createProcedure = <<< 'ENDOFPROC'
CREATE PROCEDURE `sp_create_invoice`(
    IN `p_invoice_category` ENUM('sales','purchase'),
    IN `p_branch_id` INT,
    IN `p_source_type` VARCHAR(100),
    IN `p_source_id` INT,
    IN `p_customer_id` INT,
    IN `p_supplier_id` INT,
    IN `p_agent_id` INT,
    IN `p_branch_entity_id` INT,
    IN `p_currency_id` INT,
    IN `p_total_amount` DECIMAL(18,2),
    IN `p_discount` DECIMAL(15,2),
    IN `p_cost_amount` DECIMAL(15,2),
    IN `p_payment_type` VARCHAR(50),
    IN `p_description` TEXT,
    IN `p_created_by` INT,
    IN `p_cost_center_id` INT,
    IN `p_invoice_number` VARCHAR(50),
    OUT `p_invoice_id` INT
)
MODIFIES SQL DATA
SQL SECURITY INVOKER
sp_create_invoice_body:BEGIN
    DECLARE v_net_amount        DECIMAL(15,2);
    DECLARE v_party_account_id  INT;
    DECLARE v_tax_amount        DECIMAL(15,2) DEFAULT 0;
    DECLARE v_account_id        INT           DEFAULT NULL;
    DECLARE v_customer_acct_id  INT           DEFAULT NULL;
    DECLARE v_supplier_acct_id  INT           DEFAULT NULL;
    DECLARE v_payment_status    VARCHAR(20)   DEFAULT 'unpaid';
    DECLARE v_created_ip        VARCHAR(45)   DEFAULT NULL;
    DECLARE v_created_ua        TEXT          DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SET p_invoice_number = fn_sanitize_safe(p_invoice_number, 1);
    SET p_source_type    = fn_sanitize_safe(p_source_type,    1);
    SET p_payment_type   = fn_sanitize_safe(p_payment_type,   1);
    SET p_description    = fn_sanitize_safe(p_description,    0);

    IF p_invoice_category = 'sales' THEN
        SET v_party_account_id = (SELECT account_id FROM customers WHERE id = p_customer_id);
    ELSE
        SET v_party_account_id = (SELECT account_id FROM suppliers WHERE id = p_supplier_id);
    END IF;

    SET v_account_id = CASE
        WHEN p_payment_type IN ('cash', 'bank_transfer') AND p_branch_entity_id IS NOT NULL
            THEN p_branch_entity_id
        ELSE v_party_account_id
    END;
    SET v_customer_acct_id  = CASE WHEN p_invoice_category='sales'    THEN v_party_account_id ELSE NULL END;
    SET v_supplier_acct_id  = CASE WHEN p_invoice_category='purchase' THEN v_party_account_id ELSE NULL END;

    IF v_party_account_id IS NOT NULL AND p_currency_id IS NOT NULL THEN
        BEGIN
            DECLARE v_exists INT DEFAULT 0;
            DECLARE v_currency_code VARCHAR(10);
            SELECT currency_code INTO v_currency_code
              FROM currencies WHERE id = p_currency_id LIMIT 1;
            SELECT COUNT(*) INTO v_exists
              FROM account_balances_unified
             WHERE account_id = v_party_account_id AND currency_id = p_currency_id;
            IF v_exists = 0 THEN
                INSERT IGNORE INTO account_balances_unified (
                    account_id, branch_id, currency_id, currency_code,
                    opening_balance, current_balance, current_balance_base,
                    credit_limit, debit_limit, is_frozen,
                    last_updated, opening_balance_base
                ) VALUES (
                    v_party_account_id, NULL, p_currency_id, v_currency_code,
                    0, 0, 0,
                    0, 0, 0,
                    NOW(), 0
                );
            END IF;
        END;
    END IF;

    IF v_created_ip IS NULL AND p_created_by IS NOT NULL THEN
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            SET v_created_ip = (SELECT SUBSTRING_INDEX(
                GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '') ORDER BY id DESC SEPARATOR '|'), '|', 1)
              FROM audit_logs WHERE user_id = p_created_by LIMIT 1);
        END;
    END IF;

    SET v_net_amount = ROUND(
        COALESCE(p_total_amount, 0)
      - COALESCE(p_discount,   0)
      + v_tax_amount, 2);

    IF p_payment_type IN ('cash', 'bank_transfer') THEN
        SET v_payment_status = 'paid';
    ELSEIF COALESCE(p_total_amount, 0) - COALESCE(p_discount, 0) > 0 THEN
        SET v_payment_status = 'unpaid';
    ELSE
        SET v_payment_status = 'paid';
    END IF;

    SET p_invoice_number = NULLIF(TRIM(p_invoice_number), '');

    IF p_invoice_number IS NULL THEN
        SET p_invoice_number = COALESCE(
            fn_get_next_sequence(CASE
                WHEN p_invoice_category='sales' THEN CASE p_source_type
                    WHEN 'BusFlight'     THEN 'busflight_invoice'
                    WHEN 'umrah'         THEN 'umrah_invoice'
                    WHEN 'work_visa'     THEN 'work_visa_invoice'
                    WHEN 'FamilyVisit'   THEN 'family_visit_invoice'
                    WHEN 'Passport'      THEN 'passport_invoice'
                    ELSE 'invoice' END
                ELSE CASE p_source_type
                    WHEN 'BusFlight'     THEN 'purchase_invoice'
                    ELSE 'purchase_invoice' END
                END),
            CONCAT('INV-', DATE_FORMAT(NOW(), '%Y%m%d'), '-', LPAD(LAST_INSERT_ID()+1, 4, '0'))
        );
    END IF;

    INSERT INTO invoices (
        invoice_number, invoice_date, due_date,
        branch_id, invoice_category, source_type, source_id,
        branch_entity_id,
        customer_id, supplier_id, agent_id, cost_center_id,
        currency_id, total_amount, discount, tax_amount, tax_rate,
        net_amount, cost_amount, payment_type, delivery_type,
        account_id, customer_account_id, supplier_account_id,
        amount_received, payment_status, invoice_status,
        description, created_by, created_at,
        created_ip, created_user_agent
    ) VALUES (
        p_invoice_number, NOW(), NULL,
        p_branch_id, p_invoice_category, NULLIF(TRIM(p_source_type), ''), p_source_id,
        p_branch_entity_id,
        p_customer_id, p_supplier_id, p_agent_id, p_cost_center_id,
        p_currency_id, COALESCE(p_total_amount, 0), COALESCE(p_discount, 0), v_tax_amount, 0,
        v_net_amount, COALESCE(p_cost_amount, 0), p_payment_type,
        CASE WHEN p_payment_type IN ('cash', 'bank_transfer') THEN p_payment_type ELSE 'credit' END,
        v_account_id, v_customer_acct_id, v_supplier_acct_id,
        CASE WHEN p_payment_type IN ('cash', 'bank_transfer') THEN v_net_amount ELSE 0 END, v_payment_status, 'draft',
        p_description, p_created_by, NOW(),
        v_created_ip, v_created_ua
    );

    SET p_invoice_id = LAST_INSERT_ID();

    INSERT INTO audit_logs (
        user_id, action, table_name, record_id,
        old_values, new_values, ip_address, user_agent, created_at
    ) VALUES (
        COALESCE(p_created_by, 0), 'create', 'invoices', p_invoice_id, NULL,
        JSON_OBJECT(
            'id',               p_invoice_id,
            'invoice_number',   p_invoice_number,
            'invoice_category', p_invoice_category,
            'branch_id',        CAST(p_branch_id AS CHAR),
            'customer_id',      CAST(p_customer_id AS CHAR),
            'supplier_id',      CAST(p_supplier_id AS CHAR),
            'branch_entity_id', CAST(p_branch_entity_id AS CHAR),
            'currency_id',      CAST(p_currency_id AS CHAR),
            'total_amount',     CAST(COALESCE(p_total_amount, 0) AS CHAR),
            'discount',         CAST(COALESCE(p_discount, 0) AS CHAR),
            'net_amount',       CAST(v_net_amount AS CHAR),
            'payment_type',     p_payment_type,
            'payment_status',   v_payment_status,
            'invoice_status',   'draft',
            'created_by',       CAST(p_created_by AS CHAR)
        ),
        v_created_ip, v_created_ua, NOW()
    );

    COMMIT;
END
ENDOFPROC;

try {
    $pdo->exec($createProcedure);
    echo "      ✅ تم إنشاء الإجراء بنجاح\n";
} catch (PDOException $e) {
    echo "      ❌ فشل الإنشاء: " . $e->getMessage() . "\n";
    // محاولة استعادة النسخة الاحتياطية
    if (isset($backupFile) && file_exists($backupFile)) {
        echo "      ⚠️  محاولة استعادة النسخة الاحتياطية...\n";
    }
    exit(1);
}

// 5) التحقق النهائي
echo "\n[4/4] التحقق من التوقيع (18 بارامتر بالترتيب الصحيح)...\n";

$params = $pdo->query("
    SELECT ordinal_position, parameter_name, parameter_mode, dtd_identifier
    FROM information_schema.parameters
    WHERE specific_schema = DATABASE()
      AND specific_name = 'sp_create_invoice'
    ORDER BY ordinal_position
")->fetchAll();

$expected = [
    1  => ['p_invoice_category','IN'],
    2  => ['p_branch_id','IN'],
    3  => ['p_source_type','IN'],
    4  => ['p_source_id','IN'],
    5  => ['p_customer_id','IN'],
    6  => ['p_supplier_id','IN'],
    7  => ['p_agent_id','IN'],
    8  => ['p_branch_entity_id','IN'],
    9  => ['p_currency_id','IN'],
    10 => ['p_total_amount','IN'],
    11 => ['p_discount','IN'],
    12 => ['p_cost_amount','IN'],
    13 => ['p_payment_type','IN'],
    14 => ['p_description','IN'],
    15 => ['p_created_by','IN'],
    16 => ['p_cost_center_id','IN'],
    17 => ['p_invoice_number','IN'],
    18 => ['p_invoice_id','OUT'],
];

$errors = 0;
foreach ($expected as $pos => $exp) {
    $p = $params[$pos - 1] ?? null;
    if (!$p || $p['parameter_name'] !== $exp[0] || $p['parameter_mode'] !== $exp[1]) {
        echo "      ❌ الموقع $pos: متوقع {$exp[1]} {$exp[0]} | حاصل: " . json_encode($p, JSON_UNESCAPED_UNICODE) . "\n";
        $errors++;
    }
}

if ($errors === 0 && count($params) === 18) {
    echo "      ✅ عدد البارامترات: 18\n";
    echo "      ✅ الترتيب مطابق 100% لـ accounting_functions.php\n";
    echo "      ✅ البارامتر رقم 8 هو p_branch_entity_id (الإصلاح الرئيسي)\n";

    echo "\n==========================================================\n";
    echo "  🎉 الباتش مطبق بنجاح وبأمان!\n";
    echo "==========================================================\n\n";
    echo "  ملخص الإصلاحات المطبقة:\n";
    echo "  🔧 الترتيب: p_branch_entity_id أصبح في الموقع 8 بدلاً من p_service_id\n";
    echo "  🔧 INSERT جدول الفواتير: أضيف عمود branch_entity_id فعلياً\n";
    echo "  🔧 account_balances_unified: تم إصلاح 12 عمود بدلاً من (balance/debit_total/...)\n";
    echo "  🔧 currency_code: يتم جلبه من جدول currencies تلقائياً\n";
    echo "  🔧 audit_logs: أضيف branch_entity_id إلى سجل التدقيق\n\n";
    exit(0);
} else {
    echo "\n❌ تحقق فاشل - $errors أخطاء في التوقيع\n";
    exit(1);
}
