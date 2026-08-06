# Performance Comparison Report

## Latest lightweight benchmark - 2026-08-07

Command: `php tools/finance_performance_benchmark.php` (1,000 in-memory normalization iterations).

| Operation | Legacy | Facade |
|---|---:|---:|
| Payload normalization (ms) | 4.120 | 3.887 |

This is a micro-benchmark only. It does not claim database-operation performance equivalence; the isolated end-to-end integration tests are the acceptance evidence for financial behavior.

تاريخ القياس: 2026-08-06

## نطاق القياس

تم إنشاء `tools/finance_performance_benchmark.php` لقياس العمليات التي لا تحتاج إلى قاعدة بيانات:

- تطبيع الحمولة المالية.
- مقارنة استدعاء المنطق القديم باستدعاء الـ Facade الجديد.

العمليات التي تعتمد على Stored Procedures وMySQL لا يمكن قياسها حاليًا لأن خدمة MySQL المحلية غير متاحة.

## الحالة

- ✅ تم إنشاء أداة القياس وتشغيلها بنتيجة قابلة للتكرار.
- ✅ تم الحفاظ على نفس مسار التنفيذ للمنطق المالي الحالي.
- ⏳ قياس العمليات المالية الحقيقية قبل/بعد وعدد الاستعلامات والذاكرة وزمن تحميل الصفحات: يحتاج سيناريو أداء مستقلًا على نسخة اختبار.

## معيار القبول

لا يعتمد اعتماد الأداء النهائي إلا بعد مقارنة إنشاء وترحيل الفواتير والسندات وتحديث الأرصدة على قاعدة اختبار، مع تسجيل الزمن وعدد استعلامات SQL واستهلاك الذاكرة.
