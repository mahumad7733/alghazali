
import os

# قراءة الملف الأصلي
with open('alghazali.sql', 'r', encoding='utf-8') as f:
    lines = f.readlines()

# البحث عن المكان الصحيح لإضافة التعليمات
new_lines = []
insert_done = False

for line in lines:
    new_lines.append(line)
    if '-- قاعدة بيانات: `alghazali`' in line and not insert_done:
        # إضافة السطر "--" التالي
        new_lines.append('\n')
        # إضافة التعليمات الجديدة
        new_lines.append('-- إنشاء قاعدة البيانات إذا لم تكن موجودة مع الترميز المطلوب\n')
        new_lines.append('CREATE DATABASE IF NOT EXISTS `alghazali` \n')
        new_lines.append('/*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci */;\n')
        new_lines.append('USE `alghazali`;\n')
        new_lines.append('\n')
        insert_done = True

# كتابة الملف الجديد
with open('alghazali_updated.sql', 'w', encoding='utf-8') as f:
    f.writelines(new_lines)

# استبدال الملف الأصلي
os.replace('alghazali_updated.sql', 'alghazali.sql')

print('✅ تم تعديل الملف بنجاح!')
