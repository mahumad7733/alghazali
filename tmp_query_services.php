<?php
require 'includes/db.php';
$stmt = $pdo->query("SELECT id, service_name, service_type, status FROM services ORDER BY id");
file_put_contents('tmp_services_output.txt', json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
