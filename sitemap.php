<?php
/**
 * خريطة الموقع (Sitemap) - وكالة الغزالي للسفريات والسياحة
 * توافق كامل مع معايير جوجل وأدوات مشرفي المواقع
 */

header("Content-Type: application/xml; charset=utf-8");
header("Cache-Control: public, max-age=86400"); // تخزين مؤقت لمدة 24 ساعة

require_once 'includes/db.php';

// تحديد عنوان URL الأساسي
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];

// بدء ملف XML
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" ' . "\n";
echo '         xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" ' . "\n";
echo '         xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 ' . "\n";
echo '         http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">' . "\n";

// الصفحات الأساسية الثابتة
$pages = [
    [
        'url' => '/index.php', 
        'priority' => '1.0', 
        'changefreq' => 'daily',
        'lastmod' => date('Y-m-d')
    ],
    [
        'url' => '/about.php', 
        'priority' => '0.8', 
        'changefreq' => 'monthly',
        'lastmod' => date('Y-m-d')
    ],
    [
        'url' => '/services.php', 
        'priority' => '0.9', 
        'changefreq' => 'weekly',
        'lastmod' => date('Y-m-d')
    ],
    [
        'url' => '/contact.php', 
        'priority' => '0.7', 
        'changefreq' => 'monthly',
        'lastmod' => date('Y-m-d')
    ],
    [
        'url' => '/subscribe.php', 
        'priority' => '0.6', 
        'changefreq' => 'monthly',
        'lastmod' => date('Y-m-d')
    ],
];

// إضافة الصفحات الثابتة
foreach ($pages as $page) {
    echo '<url>' . "\n";
    echo '  <loc>' . htmlspecialchars($base_url . $page['url']) . '</loc>' . "\n";
    echo '  <lastmod>' . $page['lastmod'] . '</lastmod>' . "\n";
    echo '  <changefreq>' . $page['changefreq'] . '</changefreq>' . "\n";
    echo '  <priority>' . $page['priority'] . '</priority>' . "\n";
    echo '</url>' . "\n";
}

// إضافة الخدمات ديناميكياً من قاعدة البيانات
try {
    $stmt = $pdo->query("SELECT id, created_at FROM services ORDER BY id DESC");
    while($row = $stmt->fetch()) {
        $lastmod = date('Y-m-d', strtotime($row['created_at'] ?? 'now'));
        echo '<url>' . "\n";
        echo '  <loc>' . htmlspecialchars($base_url . '/services.php?id=' . $row['id']) . '</loc>' . "\n";
        echo '  <lastmod>' . $lastmod . '</lastmod>' . "\n";
        echo '  <changefreq>weekly</changefreq>' . "\n";
        echo '  <priority>0.7</priority>' . "\n";
        echo '</url>' . "\n";
    }
} catch (Exception $e) {
    // تجاهل الخطأ إذا لم يكن الجدول موجوداً
    error_log("Sitemap Error - Services: " . $e->getMessage());
}

// إضافة الأخبار ديناميكياً من قاعدة البيانات
try {
    $stmt = $pdo->query("SELECT id, created_at FROM news WHERE status = 1 ORDER BY id DESC LIMIT 50");
    while($row = $stmt->fetch()) {
        $lastmod = date('Y-m-d', strtotime($row['created_at'] ?? 'now'));
        echo '<url>' . "\n";
        echo '  <loc>' . htmlspecialchars($base_url . '/news.php?id=' . $row['id']) . '</loc>' . "\n";
        echo '  <lastmod>' . $lastmod . '</lastmod>' . "\n";
        echo '  <changefreq>weekly</changefreq>' . "\n";
        echo '  <priority>0.6</priority>' . "\n";
        echo '</url>' . "\n";
    }
} catch (Exception $e) {
    // تجاهل الخطأ إذا لم يكن الجدول موجوداً
    error_log("Sitemap Error - News: " . $e->getMessage());
}

// إضافة الدفعات ديناميكياً من قاعدة البيانات
try {
    $stmt = $pdo->query("SELECT id, created_at FROM batches WHERE status = 1 ORDER BY id DESC LIMIT 30");
    while($row = $stmt->fetch()) {
        $lastmod = date('Y-m-d', strtotime($row['created_at'] ?? 'now'));
        echo '<url>' . "\n";
        echo '  <loc>' . htmlspecialchars($base_url . '/batches.php?id=' . $row['id']) . '</loc>' . "\n";
        echo '  <lastmod>' . $lastmod . '</lastmod>' . "\n";
        echo '  <changefreq>daily</changefreq>' . "\n";
        echo '  <priority>0.8</priority>' . "\n";
        echo '</url>' . "\n";
    }
} catch (Exception $e) {
    // تجاهل الخطأ إذا لم يكن الجدول موجوداً
    error_log("Sitemap Error - Batches: " . $e->getMessage());
}

// إغلاق ملف XML
echo '</urlset>' . "\n";
?>
