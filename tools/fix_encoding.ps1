# Script PowerShell لإصلاح الترميز في ملف SQL
$inputFile = "alghazali.sql"
$outputFile = "alghazali_fixed.sql"

# قراءة الملف باستخدام ترميز Windows-1252 (أو ISO-8859-1)
$content = [System.IO.File]::ReadAllText($inputFile, [System.Text.Encoding]::GetEncoding(1252))

# حفظ الملف باستخدام ترميز UTF-8 مع BOM
[System.IO.File]::WriteAllText($outputFile, $content, [System.Text.Encoding]::UTF8)

Write-Host "تم إصلاح الترميز بنجاح!"
Write-Host "الملف الأصلي: $inputFile"
Write-Host "الملف المُصَحَّح: $outputFile"
