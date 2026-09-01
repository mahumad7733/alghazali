<?php
declare(strict_types=1);

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

$urls = [
    ['loc' => 'https://rihla.kesug.com/', 'priority' => '1.0'],
    ['loc' => 'https://rihla.kesug.com/about.php', 'priority' => '0.7'],
    ['loc' => 'https://rihla.kesug.com/contact.php', 'priority' => '0.6'],
    ['loc' => 'https://rihla.kesug.com/privacy.php', 'priority' => '0.3'],
    ['loc' => 'https://rihla.kesug.com/developers.php', 'priority' => '0.5'],
];

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
foreach ($urls as $item) {
    echo '<url><loc>' . htmlspecialchars($item['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc><changefreq>weekly</changefreq><priority>' . $item['priority'] . '</priority></url>';
}
echo '</urlset>';
