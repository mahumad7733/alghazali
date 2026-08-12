<?php
require_once __DIR__ . '/includes/db.php';

echo "<h2>تطبيق ترحيل نظام الموردين - طريقة مباشرة...</h2><hr>";

$errors = [];

// ============================================================
// القسم 1: إضافة حقل الاسم التجاري إلى جدول suppliers
// ============================================================
echo "<h3>القسم 1: إضافة حقل الاسم التجاري (trade_name)</h3>";

$checkCol = $pdo->query("SHOW COLUMNS FROM suppliers LIKE 'trade_name'")->fetch();
if (!$checkCol) {
    try {
        $pdo->exec("ALTER TABLE suppliers ADD COLUMN trade_name VARCHAR(255) NULL COMMENT 'الاسم التجاري للمورد - يظهر في واجهة المستخدم' AFTER supplier_name");
        echo "<p style='color:green'>✅ تم إضافة حقل trade_name بنجاح</p>";
    } catch (Exception $e) {
        $errors[] = "إضافة trade_name: " . $e->getMessage();
        echo "<p style='color:orange'>⚠️ " . htmlspecialchars($e->getMessage()) . "</p>";
    }
} else {
    echo "<p style='color:green'>✅ حقل trade_name موجود بالفعل</p>";
}

// فهرس للبحث السريع
$checkIdx = $pdo->query("SHOW INDEX FROM suppliers WHERE Key_name = 'idx_suppliers_trade_name'")->fetch();
if (!$checkIdx) {
    try {
        $pdo->exec("ALTER TABLE suppliers ADD INDEX idx_suppliers_trade_name (trade_name)");
        echo "<p style='color:green'>✅ تم إضافة الفهرس بنجاح</p>";
    } catch (Exception $e) {
        echo "<p style='color:orange'>⚠️ " . htmlspecialchars($e->getMessage()) . "</p>";
    }
} else {
    echo "<p style='color:green'>✅ الفهرس موجود بالفعل</p>";
}

// ============================================================
// القسم 2: إنشاء جدول catalog_services
// ============================================================
echo "<h3>القسم 2: إنشاء جدول catalog_services</h3>";

$checkTable = $pdo->query("SHOW TABLES LIKE 'catalog_services'")->fetch();
if (!$checkTable) {
    try {
        $pdo->exec("
            CREATE TABLE catalog_services (
                id INT AUTO_INCREMENT PRIMARY KEY,
                service_code VARCHAR(50) NOT NULL UNIQUE COMMENT 'رمز الخدمة الفريد',
                service_name_ar VARCHAR(255) NOT NULL COMMENT 'اسم الخدمة بالعربية',
                service_name_en VARCHAR(100) NULL COMMENT 'اسم الخدمة بالإنجليزية',
                description TEXT NULL COMMENT 'وصف الخدمة',
                is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل الخدمة نشطة؟',
                sort_order INT NOT NULL DEFAULT 0 COMMENT 'ترتيب العرض',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL DEFAULT NULL,
                INDEX idx_catalog_services_code (service_code),
                INDEX idx_catalog_services_active (is_active, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'جدول الخدمات المتاحة في النظام'
        ");
        echo "<p style='color:green'>✅ تم إنشاء جدول catalog_services بنجاح</p>";
    } catch (Exception $e) {
        $errors[] = "إنشاء catalog_services: " . $e->getMessage();
        echo "<p style='color:red'>❌ " . htmlspecialchars($e->getMessage()) . "</p>";
    }
} else {
    echo "<p style='color:green'>✅ جدول catalog_services موجود بالفعل</p>";
}

// ============================================================
// القسم 3: إدراج الخدمات الأساسية الثمانية
// ============================================================
echo "<h3>القسم 3: إدراج الخدمات الأساسية</h3>";

$services = [
    ['bus_bookings',           'حجوزات الباصات',        'Bus Bookings',            'خدمات حجوزات وشراء تذاكر الباصات',              1, 1],
    ['flight_bookings',        'حجوزات الطيران',        'Flight Bookings',         'خدمات حجوزات وشراء تذاكر الطيران',              1, 2],
    ['umrah',                  'خدمات العمرة',          'Umrah Services',          'خدمات العمرة (التجهيزات والخدمات المرتبطة)',    1, 3],
    ['hajj',                   'خدمات الحج',            'Hajj Services',           'خدمات الحج (التجهيزات والخدمات المرتبطة)',      1, 4],
    ['work_visa',              'تأشيرات العمل',         'Work Visa',               'خدمات تأشيرات العمل للخارج',                    1, 5],
    ['family_visit',           'الزيارة العائلية',      'Family Visit Visa',       'خدمات الزيارة العائلية وتأشيراتها',             1, 6],
    ['passport_transactions',  'معاملات الجوازات',      'Passport Transactions',   'خدمات معاملات الجوازات والبطائق الشخصية',       1, 7],
    ['postal_services',        'الخدمات البريدية',      'Postal Services',         'خدمات الشحن والبريد والطرود',                   1, 8],
];

$inserted = 0;
foreach ($services as $svc) {
    list($code, $nameAr, $nameEn, $desc, $active, $order) = $svc;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO catalog_services (service_code, service_name_ar, service_name_en, description, is_active, sort_order)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                service_name_ar = VALUES(service_name_ar),
                service_name_en = VALUES(service_name_en),
                description = VALUES(description),
                is_active = VALUES(is_active),
                sort_order = VALUES(sort_order)
        ");
        $stmt->execute([$code, $nameAr, $nameEn, $desc, $active, $order]);
        $inserted++;
    } catch (Exception $e) {
        echo "<p style='color:orange'>⚠️ {$code}: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}
echo "<p style='color:green'>✅ تم معالجة $inserted خدمة</p>";

$svcCount = $pdo->query("SELECT COUNT(*) FROM catalog_services")->fetchColumn();
echo "<p>عدد الخدمات الحالي في الجدول: <strong>$svcCount</strong></p>";

// ============================================================
// القسم 4: إنشاء جدول ربط supplier_services
// ============================================================
echo "<h3>القسم 4: إنشاء جدول ربط supplier_services</h3>";

$checkTable2 = $pdo->query("SHOW TABLES LIKE 'supplier_services'")->fetch();
if (!$checkTable2) {
    try {
        $pdo->exec("
            CREATE TABLE supplier_services (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                supplier_id INT NOT NULL COMMENT 'معرف المورد',
                service_id INT NOT NULL COMMENT 'معرف الخدمة',
                is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل المورد يقدم الخدمة حالياً؟',
                assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                assigned_by INT NULL COMMENT 'معرف المستخدم الذي قام بالربط',
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_supplier_service (supplier_id, service_id),
                INDEX idx_supplier_services_supplier (supplier_id, is_active),
                INDEX idx_supplier_services_service (service_id, is_active),
                CONSTRAINT fk_supplier_services_supplier
                    FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_supplier_services_service
                    FOREIGN KEY (service_id) REFERENCES catalog_services(id)
                    ON DELETE RESTRICT ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'جدول ربط الموردين بالخدمات التي يقدمونها'
        ");
        echo "<p style='color:green'>✅ تم إنشاء جدول supplier_services بنجاح</p>";
    } catch (Exception $e) {
        $errors[] = "إنشاء supplier_services: " . $e->getMessage();
        echo "<p style='color:red'>❌ " . htmlspecialchars($e->getMessage()) . "</p>";
    }
} else {
    echo "<p style='color:green'>✅ جدول supplier_services موجود بالفعل</p>";
    // التحقق من وجود الأعمدة المطلوبة وإضافتها إذا كانت مفقودة
    $reqCols = ['is_active', 'assigned_at', 'assigned_by', 'updated_at'];
    foreach ($reqCols as $col) {
        $colExists = $pdo->query("SHOW COLUMNS FROM supplier_services LIKE '$col'")->fetch();
        if (!$colExists) {
            try {
                if ($col === 'is_active') {
                    $pdo->exec("ALTER TABLE supplier_services ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER service_id");
                } elseif ($col === 'assigned_at') {
                    $pdo->exec("ALTER TABLE supplier_services ADD COLUMN assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER is_active");
                } elseif ($col === 'assigned_by') {
                    $pdo->exec("ALTER TABLE supplier_services ADD COLUMN assigned_by INT NULL AFTER assigned_at");
                } elseif ($col === 'updated_at') {
                    $pdo->exec("ALTER TABLE supplier_services ADD COLUMN updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER assigned_by");
                }
                echo "<p style='color:green'>✅ تم إضافة العمود $col</p>";
            } catch (Exception $e) {
                echo "<p style='color:orange'>⚠️ إضافة $col: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
        }
    }

    // التحقق من وجود الفهارس والقيود
    $idxCheck = $pdo->query("SHOW INDEX FROM supplier_services WHERE Key_name = 'uk_supplier_service'")->fetch();
    if (!$idxCheck) {
        try {
            $pdo->exec("ALTER TABLE supplier_services ADD UNIQUE KEY uk_supplier_service (supplier_id, service_id)");
            echo "<p style='color:green'>✅ تم إضافة الفهرس الفريد</p>";
        } catch (Exception $e) {
            echo "<p style='color:orange'>⚠️ " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
}

// ============================================================
// القسم 5: ترحيل بيانات أولية - ربط جميع الموردين بجميع الخدمات
// ============================================================
echo "<h3>القسم 5: ربط الموردين الحاليين بجميع الخدمات (للتوافق الخلفي)</h3>";

$supplierIds = $pdo->query("SELECT id FROM suppliers WHERE deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN);
$serviceIds = $pdo->query("SELECT id FROM catalog_services WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
$linksAdded = 0;

if (!empty($supplierIds) && !empty($serviceIds)) {
    foreach ($supplierIds as $sid) {
        foreach ($serviceIds as $svid) {
            try {
                $stmt = $pdo->prepare("INSERT IGNORE INTO supplier_services (supplier_id, service_id, is_active, assigned_at) VALUES (?, ?, 1, NOW())");
                $stmt->execute([$sid, $svid]);
                if ($stmt->rowCount() > 0) $linksAdded++;
            } catch (Exception $e) {
                // تجاهل الأخطاء هنا
            }
        }
    }
}
echo "<p style='color:green'>✅ تم إضافة $linksAdded ربط جديد للموردين الحاليين</p>";
$currentLinks = $pdo->query("SELECT COUNT(*) FROM supplier_services")->fetchColumn();
echo "<p>إجمالي الروابط الحالية: <strong>$currentLinks</strong></p>";

// ============================================================
// القسم 6: إنشاء View المساعدة
// ============================================================
echo "<h3>القسم 6: إنشاء View المساعدة v_suppliers_with_display_name</h3>";

try {
    $pdo->exec("DROP VIEW IF EXISTS v_suppliers_with_display_name");
    $pdo->exec("
        CREATE VIEW v_suppliers_with_display_name AS
        SELECT
            s.id AS supplier_id,
            s.account_id,
            s.supplier_name,
            COALESCE(NULLIF(TRIM(s.trade_name), ''), s.supplier_name) AS display_name,
            s.trade_name,
            s.supplier_phone,
            s.supplier_email,
            s.address,
            s.status,
            s.created_at,
            s.updated_at
        FROM suppliers s
        WHERE s.deleted_at IS NULL
    ");
    echo "<p style='color:green'>✅ تم إنشاء View المساعدة بنجاح</p>";
} catch (Exception $e) {
    $errors[] = "إنشاء View: " . $e->getMessage();
    echo "<p style='color:red'>❌ " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<h2>الخلاصة النهائية</h2>";

if (empty($errors)) {
    echo "<p style='color:green;font-size:1.2em;font-weight:bold'>✅ تم تطبيق الترحيل بنجاح بدون أخطاء حرجة!</p>";
} else {
    echo "<p style='color:orange;font-weight:bold'>⚠️ تم تطبيق الترحيل مع بعض التنبيهات (قد تكون طبيعية):</p>";
    echo "<ul>";
    foreach ($errors as $e) {
        echo "<li>" . htmlspecialchars($e) . "</li>";
    }
    echo "</ul>";
}

echo "<h3>عينة من البيانات:</h3>";
echo "<h4>الخدمات:</h4>";
$services = $pdo->query("SELECT service_code, service_name_ar FROM catalog_services ORDER BY sort_order")->fetchAll();
echo "<table border='1' cellpadding='5' style='border-collapse:collapse'>";
foreach ($services as $s) {
    echo "<tr><td>{$s['service_code']}</td><td>{$s['service_name_ar']}</td></tr>";
}
echo "</table>";

echo "<h4>عينة من الموردين مع الاسم المعروض:</h4>";
$supSample = $pdo->query("SELECT supplier_id, supplier_name, trade_name, display_name FROM v_suppliers_with_display_name LIMIT 5")->fetchAll();
echo "<table border='1' cellpadding='5' style='border-collapse:collapse'>";
echo "<tr><th>ID</th><th>Supplier Name</th><th>Trade Name</th><th>Display Name</th></tr>";
foreach ($supSample as $s) {
    echo "<tr>
            <td>{$s['supplier_id']}</td>
            <td>{$s['supplier_name']}</td>
            <td>" . htmlspecialchars($s['trade_name'] ?? '') . "</td>
            <td>" . htmlspecialchars($s['display_name']) . "</td>
          </tr>";
}
echo "</table>";
?>
