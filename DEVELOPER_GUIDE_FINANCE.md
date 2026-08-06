# Finance Developer Guide

## استخدام الخدمة

```php
require_once __DIR__ . '/core/FinanceService.php';
$finance = new FinanceService($pdo, $userId);
$invoiceId = $finance->createInvoiceDraft($data, 'sales');
```

يجب استخدام الـ Facade في الاستدعاءات الجديدة، وعدم استدعاء `LegacyFinanceService` مباشرة.

## إضافة خدمة جديدة

1. إنشاء Service تحت `core/Finance/`.
2. إنشاء Contract عند الحاجة.
3. حقن الاعتماديات في Constructor.
4. إضافة التفويض في `FinanceService` مع الحفاظ على التوقيع القديم.
5. إضافة اختبار Smoke واختبار تكامل.
6. تحديث التوثيق ثم إنشاء Commit مستقل.
