from pathlib import Path
import hashlib
import shutil
import zipfile

ROOT = Path('/home/ubuntu/bus-booking-system')
SOURCE = ROOT / 'infinityfree'
OUT = ROOT / 'release'
PACKAGE = OUT / 'rihla-bus-booking-system'
ZIP_PATH = OUT / 'rihla-bus-booking-system-safe.zip'

if OUT.exists():
    shutil.rmtree(OUT)
PACKAGE.mkdir(parents=True)

EXCLUDED_NAMES = {
    '.git', '.gitignore', 'config.php',
    'enable_mohammed_username.php', 'enable_mohammed_username_get.php',
    'password_hash_tool.php',
}
EXCLUDED_DIRS = {'.git', '__pycache__'}

def ignore(path: str) -> bool:
    p = Path(path)
    return p.name in EXCLUDED_NAMES or p.name in EXCLUDED_DIRS

app_dest = PACKAGE / 'infinityfree'
shutil.copytree(SOURCE, app_dest, ignore=lambda directory, names: [name for name in names if ignore(str(Path(directory) / name))])

readme = '''# نظام حجز الحافلات — نسخة آمنة للتشغيل

هذا الأرشيف يحتوي على نسخة التطبيق الحالية داخل مجلد `infinityfree/`، بما في ذلك ملفات PHP وواجهات RTL وCSS وJavaScript وملفات قاعدة البيانات والاختبارات والـ migrations.

## قبل التشغيل

لأسباب أمنية لم يتم تضمين ملف `infinityfree/config/config.php` الذي يحتوي على بيانات اتصال قاعدة البيانات الحقيقية. استخدم الملف النموذجي الموجود في `infinityfree/config/config.php.example`، وانسخه باسم `config.php` ثم أدخل بيانات MySQL الخاصة بك.

لا يحتوي الأرشيف على كلمات مرور لوحة التحكم أو بيانات FTP أو مفاتيح API أو أي أداة نشر مرتبطة بحساب الاستضافة.

## تثبيت قاعدة البيانات

توجد ملفات قاعدة البيانات داخل `infinityfree/database/`. ابدأ بملف `schema.sql` في قاعدة بيانات جديدة، ثم طبّق ملفات migrations المطلوبة بالترتيب الموضح في أسماء الملفات أو في `INSTALL_AR.md`. استخدم `seed.sql` فقط عند إنشاء بيئة جديدة؛ لا تطبّقه فوق قاعدة بيانات إنتاجية تحتوي على بيانات حقيقية إلا بعد أخذ نسخة احتياطية ومراجعة أوامر الإدراج.

هذه النسخة تتضمن مخطط قاعدة البيانات وملفات التهيئة، وليست dump لبيانات قاعدة البيانات الحية على الاستضافة. بيانات الإنتاج لا تُحزم داخل ملف قابل للتنزيل حفاظًا على الخصوصية.

## الرفع إلى InfinityFree

ارفع محتويات مجلد `infinityfree/` إلى مجلد `htdocs` في الاستضافة، ثم تأكد من وجود `config/config.php` بصلاحيات مناسبة. لا ترفع ملف إعدادات يحوي كلمة المرور إلى مستودع عام أو تشاركه مع أي شخص.

## الاختبارات

ملفات الاختبار غير المدمرة موجودة في `infinityfree/testing/`. بعض الاختبارات تحتاج Chrome محليًا أو جلسة دخول، ولذلك تُستخدم بعد إعداد البيئة فقط. لا توجد داخل النسخة أدوات تغيير كلمات المرور أو تفعيل حسابات تجريبية.

## نطاق هذه النسخة

تم الحفاظ على الجداول والحقول والبيانات الموجودة في كود المشروع، مع تضمين الإصلاحات الحالية الخاصة بواجهة الإدارة، النوافذ المنبثقة، الشركات والإحداثيات والخريطة، إعدادات الموقع، رسائل اتصل بنا، العملات، أسعار الصرف، وفصل وظائف الوكلاء والمالية.
'''
(PACKAGE / 'README_AR.md').write_text(readme, encoding='utf-8')

# Include a short manifest so the recipient can verify the archive after extraction.
files = sorted(p for p in PACKAGE.rglob('*') if p.is_file())
manifest = []
for path in files:
    digest = hashlib.sha256(path.read_bytes()).hexdigest()
    manifest.append(f"{digest}  {path.relative_to(PACKAGE).as_posix()}")
(PACKAGE / 'SHA256SUMS.txt').write_text('\n'.join(manifest) + '\n', encoding='utf-8')

with zipfile.ZipFile(ZIP_PATH, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for path in sorted(PACKAGE.rglob('*')):
        if path.is_file():
            archive.write(path, path.relative_to(OUT).as_posix())

print(f'package={PACKAGE}')
print(f'zip={ZIP_PATH}')
print(f'files={len(files)}')
print(f'bytes={ZIP_PATH.stat().st_size}')
