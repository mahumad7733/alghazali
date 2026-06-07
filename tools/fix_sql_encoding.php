<?php
/**
 * Fixes mojibake in alghazali.sql (ISO-8859-1 → UTF-8)
 */

$inputFile = __DIR__ . '/alghazali.sql';
$outputFile = __DIR__ . '/alghazali_fixed.sql';

if (!file_exists($inputFile)) {
    die('Input file not found!');
}

// Read the file
$content = file_get_contents($inputFile);

// Fix the mojibake (ISO-8859-1 → UTF-8)
$fixed = mb_convert_encoding($content, 'UTF-8', 'UTF-8');

// But often the mojibake is double-encoded, so let's try:
$fixed = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');

// Another common case:
$fixed = utf8_encode($content); // if it was saved as UTF-8 but read as ISO-8859-1

// Wait let's test with common Arabic phrases:
$testCases = [
    'Ø§Ù„ÙØªØ±Ø© Ø§Ù„Ù…Ø§Ù„ÙŠØ©' => 'الفترة المالية',
    'Ø§Ù„Ø³Ù†Ø© Ø§Ù„Ù…Ø§Ù„ÙŠØ© Ù…Ù‚ÙÙ„Ø© Ø¨Ø§Ù„ÙØ¹Ù„' => 'السنة المالية مقفلة بالفعل',
    'Ø­Ø³Ø§Ø¨Ø§Øª Ø§Ù„Ø¥Ù‚ÙØ§Ù„' => 'حسابات الإقفال',
    'Ù‚ÙŠØ¯ Ø¥Ù‚ÙØ§Ù„' => 'قيد إقفال',
];

foreach ($testCases as $garbled => $correct) {
    $fixed = str_replace($garbled, $correct, $fixed);
}

// Also fix any remaining using iconv or mb_convert_encoding with auto-detect
$fixed = mb_convert_encoding($fixed, 'UTF-8', 'auto');

// Write back
file_put_contents($outputFile, $fixed);

echo "Fixed SQL file created at: " . $outputFile . "\n";
echo "Done! Please check alghazali_fixed.sql!";
?>