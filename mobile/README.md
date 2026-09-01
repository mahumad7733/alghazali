# تطبيق رحلة المحمول

هذا تطبيق Flutter واحد لـ Android وiOS، موضوع داخل النظام الحالي دون تعديل Backend.

## التشغيل

```bash
cd mobile
flutter pub get
flutter run --dart-define=RIHLA_API_BASE_URL=http://10.0.2.2/rihla/api/v1/index.php
```

على جهاز iPhone استخدم عنوان الشبكة المحلي لخادم Apache بدل `10.0.2.2`.

التطبيق يستهلك API الحالي في `../api/v1/index.php`، ولا يحتوي على رحلات أو أسعار أو مقاعد وهمية. عند عدم ضبط العنوان يستخدم المسار النسبي نفسه عبر `RIHLA_API_BASE_URL` الافتراضي.

