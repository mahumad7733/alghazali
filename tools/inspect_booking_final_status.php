<?php
require_once __DIR__ . '/../includes/db.php';
$q = $pdo->query("SELECT id, status_name, status_color FROM statuses WHERE status_name IN ('مسافر','سافر','تم تأكيد الحجز','حجز جديد') OR status_name LIKE '%مسافر%'");
$statuses = $q->fetchAll(PDO::FETCH_ASSOC);
$q = $pdo->query("SELECT w.id workflow_id,w.transaction_type,w.branch_id,ws.id step_id,ws.status_id,ws.step_name,ws.is_final,s.status_name FROM workflows w JOIN workflow_steps ws ON ws.workflow_id=w.id JOIN statuses s ON s.id=ws.status_id WHERE w.transaction_type IN ('bus_flight_bookings','booking','bus_bookings','flight_bookings') ORDER BY w.id,ws.sort_order,ws.id");
$steps = $q->fetchAll(PDO::FETCH_ASSOC);
$q = $pdo->query("SELECT b.id,b.workflow_id,b.status_id,s.status_name FROM bus_flight_bookings b LEFT JOIN statuses s ON s.id=b.status_id WHERE b.id=18");
$booking = $q->fetch(PDO::FETCH_ASSOC);
$q = $pdo->query("SELECT id,workflow_id,from_step_id,to_step_id,role_id,require_approval FROM workflow_transitions WHERE workflow_id=5 ORDER BY id");
$transitions = $q->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(compact('statuses','steps','booking','transitions'), JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), PHP_EOL;
