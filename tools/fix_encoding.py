# -*- coding: utf-8 -*-
import os

# ملفات الإدخال والإخراج
input_file = 'alghazali.sql'
output_file = 'alghazali_fixed.sql'

# قراءة الملف باستخدام ترميز Windows-1252
with open(input_file, 'r', encoding='cp1252') as f:
    content = f.read()

# حفظ الملف باستخدام ترميز UTF-8
with open(output_file, 'w', encoding='utf-8') as f:
    f.write(content)

print(f"✅ تم إصلاح الترميز بنجاح!")
print(f"📄 الملف الأصلي: {input_file}")
print(f"📄 الملف المُصَحَّح: {output_file}")
