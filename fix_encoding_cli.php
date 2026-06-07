<?php
$filePath = __DIR__ . '/tools/ghazali (14).sql';

echo "Reading file...\n";
$content = file_get_contents($filePath);

// Fix double encoding: treat as Windows-1252, then convert to UTF-8
echo "Fixing encoding...\n";
$fixed = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');

echo "Writing back...\n";
file_put_contents($filePath, $fixed);

echo "✅ ALL DONE! File fixed!\n";
?>