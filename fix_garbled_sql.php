<?php
// File paths
$inputFile = __DIR__ . '/tools/ghazali (14).sql';
$outputFile = __DIR__ . '/tools/ghazali (14)_fixed.sql';

// Read the input file
$content = file_get_contents($inputFile);

// Try to convert from Windows-1252 to UTF-8 (common issue with Arabic garbled text)
$fixedContent = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');

// If that didn't work, try ISO-8859-1 to UTF-8
if (strpos($fixedContent, '�') !== false) {
    $fixedContent = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
}

// Save the fixed content
file_put_contents($outputFile, $fixedContent);

echo "✅ File fixed successfully!\n";
echo "📄 Fixed file saved as: ghazali (14)_fixed.sql\n";
?>