<?php
// ملف لإصلاح الترميز في ملف SQL
$inputFile = 'alghazali.sql';
$outputFile = 'alghazali_fixed.sql';

// قراءة الملف الأصلي
$content = file_get_contents($inputFile);

if ($content === false) {
    die("خطأ في قراءة الملف الأصلي\n");
}

// إصلاح الترميز: تحويل من Windows-1252 إلى UTF-8
// لأن هذا هو النمط الأكثر شيوعًا لظهور هذا النوع من الأخطاء
$fixedContent = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');

// إذا لم يعمل التحويل السابق، جرب تحويل من ISO-8859-1
if (strpos($fixedContent, 'Ø§') !== false) {
    $fixedContent = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
}

// حفظ الملف المُصَحَّح
if (file_put_contents($outputFile, $fixedContent) === false) {
    die("خطأ في حفظ الملف المُصَحَّح\n");
}

echo "تم إصلاح الترميز بنجاح!\n";
echo "الملف الأصلي: $inputFile\n";
echo "الملف المُصَحَّح: $outputFile\n";
?>