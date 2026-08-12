<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
$pdo->beginTransaction();
$ok = change_booking_status(18, 31, 2, 'QA traveler status rollback', [], 35);
$row = $pdo->query("SELECT b.status_id, s.status_name FROM bus_flight_bookings b LEFT JOIN statuses s ON s.id=b.status_id WHERE b.id=18")->fetch(PDO::FETCH_ASSOC);
$pdo->rollBack();
echo json_encode(['ok'=>$ok,'row_inside_transaction'=>$row], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), PHP_EOL;
