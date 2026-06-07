<?php
// Fix script to create social_links table
require_once __DIR__ . '/../includes/db.php';

try {
    // First try to drop the table if it exists (to fix "doesn't exist in engine" error)
    try {
        $pdo->exec("DROP TABLE IF EXISTS `social_links`");
        echo "ℹ️ Dropped existing table (if any)<br>";
    } catch (PDOException $e) {
        echo "ℹ️ Could not drop table (maybe it doesn't exist properly): " . $e->getMessage() . "<br>";
    }

    // Create social_links table
    $sql = "CREATE TABLE `social_links` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `platform_name` varchar(50) NOT NULL,
        `platform_icon` varchar(50) NOT NULL,
        `link_url` text NOT NULL,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdo->exec($sql);
    echo "✅ Table 'social_links' created successfully!<br>";

    // Insert sample data
    $insertSql = "INSERT INTO `social_links` (`id`, `platform_name`, `platform_icon`, `link_url`, `created_at`) VALUES
        (1, 'WhatsApp', 'fab fa-whatsapp', 'https://wa.me/967770105284', '2026-02-16 22:24:56'),
        (2, 'Facebook', 'fab fa-facebook-f', 'https://www.facebook.com/share/17z3ECNQWQ/', '2026-02-16 22:43:38')";
    $pdo->exec($insertSql);
    echo "✅ Sample data inserted!<br>";

    echo "🎉 All done! The error should now be fixed!";

} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>