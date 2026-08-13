<?php
declare(strict_types=1);

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$user = getenv('DB_USER') ?: 'alghazali_app';
$pass = getenv('DB_PASS') ?: 'localdev';
$db   = getenv('DB_NAME') ?: 'ghazali';

$pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("SET NAMES utf8mb4");

$source = $pdo->query("SELECT * FROM workflows WHERE id = 5 AND is_active = 1 LIMIT 1")->fetch();
if (!$source) {
    throw new RuntimeException('سير العمل الأساسي رقم 5 غير موجود.');
}

$workflowSpecs = [
    'bus_bookings' => 'سير عمل حجوزات الباصات',
    'flight_bookings' => 'سير عمل حجوزات الطيران',
];

$pdo->beginTransaction();
try {
    $sourceSteps = $pdo->prepare('SELECT * FROM workflow_steps WHERE workflow_id = ? ORDER BY sort_order, id');
    $sourceSteps->execute([(int)$source['id']]);
    $sourceSteps = $sourceSteps->fetchAll();

    $sourceTransitions = $pdo->prepare('SELECT * FROM workflow_transitions WHERE workflow_id = ? ORDER BY id');
    $sourceTransitions->execute([(int)$source['id']]);
    $sourceTransitions = $sourceTransitions->fetchAll();

    $newWorkflowIds = [];
    foreach ($workflowSpecs as $transactionType => $workflowName) {
        $find = $pdo->prepare('SELECT id FROM workflows WHERE transaction_type = ? AND is_active = 1 ORDER BY id LIMIT 1');
        $find->execute([$transactionType]);
        $workflowId = (int)($find->fetchColumn() ?: 0);

        if ($workflowId === 0) {
            $insert = $pdo->prepare('INSERT INTO workflows (name, description, transaction_type, branch_id, default_status_id, is_active, created_by) VALUES (?, ?, ?, ?, NULL, 1, ?)');
            $insert->execute([$workflowName, $workflowName . ' — نسخة مستقلة من سير الحجوزات الحالي', $transactionType, $source['branch_id'], $source['created_by']]);
            $workflowId = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare('UPDATE workflows SET name = ?, description = ?, is_active = 1 WHERE id = ?')->execute([$workflowName, $workflowName . ' — سير مستقل', $workflowId]);
        }

        $stepMap = [];
        foreach ($sourceSteps as $step) {
            $findStep = $pdo->prepare('SELECT id FROM workflow_steps WHERE workflow_id = ? AND status_id = ? LIMIT 1');
            $findStep->execute([$workflowId, $step['status_id']]);
            $newStepId = (int)($findStep->fetchColumn() ?: 0);
            if ($newStepId === 0) {
                $insertStep = $pdo->prepare('INSERT INTO workflow_steps (workflow_id,status_id,step_name,step_key,sort_order,color,is_initial,is_final,is_editable,require_note,require_reason,required_fields,show_fields,show_checklist) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $insertStep->execute([$workflowId,$step['status_id'],$step['step_name'],$step['step_key'],$step['sort_order'],$step['color'],$step['is_initial'],$step['is_final'],$step['is_editable'],$step['require_note'],$step['require_reason'],$step['required_fields'],$step['show_fields'],$step['show_checklist']]);
                $newStepId = (int)$pdo->lastInsertId();
            }
            $stepMap[(int)$step['id']] = $newStepId;
        }

        foreach ($sourceTransitions as $transition) {
            $from = $stepMap[(int)$transition['from_step_id']] ?? 0;
            $to = $stepMap[(int)$transition['to_step_id']] ?? 0;
            if ($from <= 0 || $to <= 0) continue;
            $findTransition = $pdo->prepare('SELECT id FROM workflow_transitions WHERE workflow_id = ? AND from_step_id = ? AND to_step_id = ? LIMIT 1');
            $findTransition->execute([$workflowId, $from, $to]);
            if (!$findTransition->fetchColumn()) {
                $insertTransition = $pdo->prepare('INSERT INTO workflow_transitions (workflow_id,from_step_id,to_step_id,role_id,allow_by_user_id,require_approval,auto_action) VALUES (?,?,?,?,?,?,?)');
                $insertTransition->execute([$workflowId,$from,$to,$transition['role_id'],$transition['allow_by_user_id'],$transition['require_approval'],$transition['auto_action']]);
            }
        }

        $defaultOldStep = (int)$source['default_status_id'];
        if (isset($stepMap[$defaultOldStep])) {
            $pdo->prepare('UPDATE workflows SET default_status_id = ? WHERE id = ?')->execute([$stepMap[$defaultOldStep], $workflowId]);
        }
        $newWorkflowIds[$transactionType] = $workflowId;
    }

    $field = $pdo->prepare("SELECT id FROM workflow_fields WHERE field_key = 'service_type' LIMIT 1");
    $field->execute();
    $serviceFieldId = (int)($field->fetchColumn() ?: 0);
    if ($serviceFieldId === 0) {
        $insertField = $pdo->prepare("INSERT INTO workflow_fields (field_key,field_label,field_type,field_options,placeholder,is_required,is_active,sort_order) VALUES ('service_type','نوع الخدمة','select','{\"options\":[\"bus\",\"flight\"]}','اختر نوع الخدمة',1,1,205)");
        $insertField->execute();
        $serviceFieldId = (int)$pdo->lastInsertId();
    }
    $groupId = (int)$pdo->query("SELECT id FROM workflow_field_groups WHERE group_key = 'booking' LIMIT 1")->fetchColumn();
    if ($groupId > 0) {
        $map = $pdo->prepare('SELECT COUNT(*) FROM workflow_field_group_mappings WHERE field_id = ? AND group_id = ?');
        $map->execute([$serviceFieldId, $groupId]);
        if (!(int)$map->fetchColumn()) {
            $pdo->prepare('INSERT INTO workflow_field_group_mappings (field_id,group_id) VALUES (?,?)')->execute([$serviceFieldId,$groupId]);
        }
    }

    foreach ($newWorkflowIds as $transactionType => $workflowId) {
        $serviceValue = $transactionType === 'bus_bookings' ? 'bus' : 'flight';
        $pdo->prepare("UPDATE workflow_steps SET show_fields = CASE WHEN step_key = 'new' THEN CONCAT('service_type', CASE WHEN COALESCE(show_fields,'') <> '' THEN CONCAT(',',show_fields) ELSE '' END) ELSE show_fields END WHERE workflow_id = ?")->execute([$workflowId]);
        $pdo->prepare('UPDATE bus_flight_bookings SET workflow_id = ? WHERE service_type = ?')->execute([$workflowId, $serviceValue]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'workflows' => $newWorkflowIds, 'service_field_id' => $serviceFieldId], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
