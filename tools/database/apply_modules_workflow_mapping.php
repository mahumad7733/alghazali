<?php

/**
 * Migration: ربط المديولات بسير العمل - إضافة مجموعات الحقول المفقودة
 * - إضافة مجموعة الحقول: postal, crm
 * - إضافة حقول إضافية لمجموعة hajj (الخدمات البريدية لديها حقل واحد فقط حالياً)
 * - إضافة الحقول المطلوبة لكل مجموعة مع الربط الصحيح
 *
 * Idempotent: يمكن تشغيلها عدة مرات بدون أخطاء
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

echo "<h3>Migration: Modules ↔ Workflow Field Groups</h3>";
echo "<pre style='background:#f6f8fa;padding:15px;border-radius:8px;border:1px solid #d0d7de'>";

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function find_field_id($pdo, $field_key)
{
    $stmt = $pdo->prepare("SELECT id FROM workflow_fields WHERE field_key = ? LIMIT 1");
    $stmt->execute([$field_key]);
    return $stmt->fetchColumn();
}

function find_group_id($pdo, $group_key)
{
    $stmt = $pdo->prepare("SELECT id FROM workflow_field_groups WHERE group_key = ? LIMIT 1");
    $stmt->execute([$group_key]);
    return $stmt->fetchColumn();
}

function ensure_field_group($pdo, $group_key, $group_name)
{
    $id = find_group_id($pdo, $group_key);
    if ($id === false) {
        $stmt = $pdo->prepare("INSERT INTO workflow_field_groups (group_key, group_name, description, is_active, created_at) VALUES (?, ?, ?, 1, NOW())");
        $stmt->execute([$group_key, $group_name, "مجموعة حقول $group_name"]);
        echo "✅ إضافة مجموعة حقول: $group_key - $group_name\n";
        return $pdo->lastInsertId();
    }
    echo "ℹ️  مجموعة حقول موجودة مسبقاً: $group_key - $group_name\n";
    return $id;
}

function ensure_field($pdo, $field_key, $field_label, $field_type = 'text')
{
    $id = find_field_id($pdo, $field_key);
    if ($id === false) {
        $stmt = $pdo->prepare("INSERT INTO workflow_fields (field_key, field_label, field_type, is_active, created_at) VALUES (?, ?, ?, 1, NOW())");
        $stmt->execute([$field_key, $field_label, $field_type]);
        echo "  ✅ إضافة حقل: $field_key - $field_label\n";
        return $pdo->lastInsertId();
    }
    echo "  ℹ️  حقل موجود مسبقاً: $field_key\n";
    return $id;
}

function ensure_field_mapping($pdo, $field_id, $group_id)
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM workflow_field_group_mappings WHERE field_id = ? AND group_id = ?");
    $stmt->execute([$field_id, $group_id]);
    $count = $stmt->fetchColumn();
    if ($count == 0) {
        $stmt = $pdo->prepare("INSERT INTO workflow_field_group_mappings (field_id, group_id) VALUES (?, ?)");
        $stmt->execute([$field_id, $group_id]);
        return true;
    }
    return false;
}

try {
    // لا نستخدم Transaction لأن كل مرحلة Idempotent ويمكن إعادة تشغيلها
    // $pdo->beginTransaction();

    echo str_repeat('-', 80) . "\n";
    echo "المرحلة 1: التأكد من وجود مجموعات الحقول المطلوبة\n";
    echo str_repeat('-', 80) . "\n";

    $postal_group_id = ensure_field_group($pdo, 'postal', 'الخدمات البريدية');
    $crm_group_id    = ensure_field_group($pdo, 'crm', 'CRM / إدارة علاقات العملاء');
    $hajj_group_id   = find_group_id($pdo, 'hajj');
    if (!$hajj_group_id) {
        $hajj_group_id = ensure_field_group($pdo, 'hajj', 'الحج');
    }

    echo "\n" . str_repeat('-', 80) . "\n";
    echo "المرحلة 2: إضافة حقول مجموعة الحج (hajj) المفقودة\n";
    echo str_repeat('-', 80) . "\n";

    $hajj_fields = [
        ['hajj_visa_no',              'رقم تأشيرة الحج',       'text'],
        ['hajj_visa_issue_date',      'تاريخ إصدار تأشيرة الحج', 'date'],
        ['hajj_visa_expiry_date',     'تاريخ انتهاء تأشيرة الحج', 'date'],
        ['mahram_name',               'اسم المحرم',            'text'],
        ['mahram_relation',           'صلة المحرم',            'text'],
        ['hajj_package_type',         'نوع باقة الحج',         'text'],
        ['hotel_makkah',              'فندق مكة',              'text'],
        ['hotel_madinah',             'فندق المدينة',          'text'],
        ['hajj_flight_outbound',      'رحلة الذهاب',           'text'],
        ['hajj_flight_inbound',       'رحلة العودة',           'text'],
        ['hajj_flight_date_outbound', 'تاريخ رحلة الذهاب',     'date'],
        ['hajj_flight_date_inbound',  'تاريخ رحلة العودة',     'date'],
        ['maktab_number',             'رقم المكتب',            'text'],
        ['hajj_departure_date',       'تاريخ المغادرة',        'date'],
        ['hajj_return_date',          'تاريخ العودة',          'date'],
    ];

    foreach ($hajj_fields as $hf) {
        $fid = ensure_field($pdo, $hf[0], $hf[1], $hf[2]);
        if (ensure_field_mapping($pdo, $fid, $hajj_group_id)) {
            echo "  🔗 تم ربط الحقل بمجموعة hajj\n";
        }
    }

    echo "\n" . str_repeat('-', 80) . "\n";
    echo "المرحلة 3: إضافة حقول مجموعة الخدمات البريدية (postal)\n";
    echo str_repeat('-', 80) . "\n";

    $postal_fields = [
        ['tracking_no',        'رقم التتبع',          'text'],
        ['shipping_date',      'تاريخ الشحن',         'date'],
        ['delivery_date',      'تاريخ التسليم',       'date'],
        ['post_office_name',   'اسم مكتب البريد',     'text'],
        ['sender_name',        'اسم المرسل',          'text'],
        ['receiver_name',      'اسم المستلم',         'text'],
        ['package_weight',     'وزن الطرد',           'decimal'],
        ['package_type',       'نوع الطرد',           'text'],
        ['shipping_cost',      'تكلفة الشحن',         'decimal'],
        ['insurance_amount',   'قيمة التأمين',        'decimal'],
        ['tracking_url',       'رابط التتبع',         'url'],
        ['delivery_status',    'حالة التوصيل',        'select'],
        ['destination_city',   'مدينة الوجهة',        'text'],
        ['origin_city',        'مدينة الانطلاق',      'text'],
        ['received_by_customer_date', 'تاريخ استلام العميل', 'date'],
        ['postal_receipt_no',  'رقم إيصال البريد',    'text'],
    ];

    foreach ($postal_fields as $pf) {
        $fid = ensure_field($pdo, $pf[0], $pf[1], $pf[2]);
        if (ensure_field_mapping($pdo, $fid, $postal_group_id)) {
            echo "  🔗 تم ربط الحقل بمجموعة postal\n";
        }
    }

    echo "\n" . str_repeat('-', 80) . "\n";
    echo "المرحلة 4: إضافة حقول مجموعة CRM\n";
    echo str_repeat('-', 80) . "\n";

    $crm_fields = [
        ['lead_source',        'مصدر العميل المحتمل', 'select'],
        ['lead_status',        'حالة العميل المحتمل', 'select'],
        ['follow_up_date',     'تاريخ المتابعة',      'date'],
        ['customer_category',  'فئة العميل',          'select'],
        ['sales_person',       'الموظف المسؤول',      'text'],
        ['opportunity_value',  'قيمة الفرصة',         'decimal'],
        ['close_date',         'تاريخ الإغلاق',       'date'],
        ['campaign_name',      'اسم الحملة',          'text'],
        ['last_contact_date',  'تاريخ آخر تواصل',     'date'],
        ['next_action_date',   'تاريخ الإجراء التالي', 'date'],
        ['next_action_notes',  'ملاحظات الإجراء التالي', 'textarea'],
        ['customer_rating',    'تقييم العميل',        'select'],
        ['deal_stage',         'مرحلة الصفقة',        'select'],
        ['probability_pct',    'نسبة الاحتمال %',     'number'],
        ['lost_reason',        'سبب الفقدان',         'textarea'],
    ];

    foreach ($crm_fields as $cf) {
        $fid = ensure_field($pdo, $cf[0], $cf[1], $cf[2]);
        if (ensure_field_mapping($pdo, $fid, $crm_group_id)) {
            echo "  🔗 تم ربط الحقل بمجموعة crm\n";
        }
    }

    echo "\n" . str_repeat('-', 80) . "\n";
    echo "المرحلة 5: التحقق من الخدمة CRM في جدول الخدمات\n";
    echo str_repeat('-', 80) . "\n";

    $check_crm = $pdo->query("SELECT COUNT(*) FROM services WHERE service_name LIKE '%CRM%' OR service_name LIKE '%علاقات العملاء%'")->fetchColumn();
    if ($check_crm == 0) {
        $cols_stmt = $pdo->query("SHOW COLUMNS FROM services LIKE 'description'");
        $has_desc = $cols_stmt->rowCount() > 0;
        if ($has_desc) {
            $stmt = $pdo->prepare("INSERT INTO services (service_name, description, is_active, created_at) VALUES (?, ?, 1, NOW())");
            $stmt->execute(['CRM / إدارة علاقات العملاء', 'خدمات إدارة علاقات العملاء ومتابعة العملاء المحتملين']);
        } else {
            $stmt = $pdo->prepare("INSERT INTO services (service_name, is_active, created_at) VALUES (?, 1, NOW())");
            $stmt->execute(['CRM / إدارة علاقات العملاء']);
        }
        echo "✅ تمت إضافة خدمة CRM إلى جدول الخدمات\n";
    } else {
        echo "ℹ️  خدمة CRM موجودة مسبقاً\n";
    }

    // $pdo->commit();

    echo "\n" . str_repeat('=', 80) . "\n";
    echo "🎉 تمت عملية Migration بنجاح!\n";
    echo str_repeat('=', 80) . "\n\n";

    echo "التحقق النهائي - عدد الحقول لكل مجموعة:\n";
    $stmt = $pdo->query("
        SELECT g.group_key, g.group_name, COUNT(f.id) as field_count
        FROM workflow_field_groups g
        LEFT JOIN workflow_field_group_mappings gm ON g.id = gm.group_id
        LEFT JOIN workflow_fields f ON gm.field_id = f.id AND f.is_active = 1
        GROUP BY g.id, g.group_key, g.group_name
        ORDER BY g.id
    ");
    foreach ($stmt->fetchAll() as $row) {
        echo "  {$row['group_key']} ({$row['group_name']}): {$row['field_count']} حقول نشطة\n";
    }
} catch (Throwable $e) {
    // if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\n❌ خطأ أثناء Migration: " . $e->getMessage() . "\n";
    echo "المسار: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}

echo "</pre>";
