<?php
require_once 'includes/db.php';

if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['email'])) {
    $email = $_POST['email'];
    
    try {
        $stmt = $pdo->prepare("INSERT INTO subscribers (email) VALUES (?)");
        $stmt->execute([$email]);
        header("Location: index.php?subscribed=1");
    } catch (PDOException $e) {
        // إذا كان البريد موجوداً مسبقاً
        header("Location: index.php?subscribed=exists");
    }
} else {
    header("Location: index.php");
}
?>
