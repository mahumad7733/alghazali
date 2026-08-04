# Git Workflow

## نظرة عامة
اتباع استراتيجية Git Flow بسيطة حسب المطلوب:
- main: يحتوي فقط على النسخ المستقرة والجاهزة للإنتاج. لا يتم التطوير المباشر على هذا الفرع.
- Developer: فرع التطوير الرئيسي. كل الأعمال الجديدة تدمج هنا أولاً.
- feature/*: فروع لكل ميزة جديدة تبدأ من Developer.
- bugfix/*: فروع لإصلاحات الأخطاء تبدأ من Developer.
- hotfix/*: فروع لإصلاحات عاجلة تبدأ من main وتُدمج لاحقًا إلى Developer و main.

## الفروع (شرح)
- main: نسخة الإنتاج. لا تُدفع تغييرات مباشرة. التحديث عبر Pull Request بعد اختبار ودمج من Developer.
- Developer: فرع العمل اليومي. دمج كل الميزات والإصلاحات هنا بعد اختبارها.
- feature/اسم-الميزة: فرع ميزة مؤقت. يُنشأ لكل مهمة/ميزة.
- bugfix/اسم-الإصلاح: إصلاحات متوسطة تبدأ من Developer.
- hotfix/اسم-الإصلاح: إصلاحات عاجلة تبدأ من main، ثم تُدمج إلى Developer.

## إنشاء فرع جديد
من Developer:
- إنشاء فرع ميزة:
  git checkout Developer
  git pull --ff-only origin Developer
  git checkout -b feature/اسم-الميزة

- إنشاء فرع إصلاح خطأ:
  git checkout Developer
  git pull --ff-only origin Developer
  git checkout -b bugfix/اسم-الإصلاح

- إنشاء hotfix (عاجل) من main:
  git checkout main
  git pull --ff-only origin main
  git checkout -b hotfix/اسم-الإصلاح

## طريقة الدمج
- على مستوى الميزات:
  1. تطوير على feature/...
  2. اختبار محلي وتشغيل CI
  3. رفع الفرع (git push origin feature/...) ثم إنشاء Pull Request إلى Developer
  4. بعد المراجعة وتمرير CI يتم الدمج إلى Developer

- من Developer إلى main:
  1. تأكد من استقرار Developer (اختبارات، CI، مراجعات)
  2. افتح Pull Request من Developer إلى main
  3. يلزم قبول المراجعات وتمرير CI قبل الدمج
  4. استخدام "Create a merge commit" أو "Squash and merge" حسب سياسة المشروع

- hotfix:
  1. إنشاء hotfix من main
  2. بعد الإصلاح والاختبار افتح PR إلى main وادمج
  3. ثم افتح PR لدمج نفس التغيير إلى Developer (أو دمجه يدوياً)

## قواعد العمل
- لا يتم تنفيذ أي تطوير مباشر على main.
- كل التعديلات تبدأ من Developer أو feature/.. أو bugfix/.. أو hotfix/..
- قم بسحب آخر تحديثات Developer قبل إنشاء فرع جديد: git pull --ff-only origin Developer
- حافظ على الوحدات والاختبارات مضافة مع كل ميزة إذا أمكن.
- تجنب force-push على فروع مشتركة (خاصًة Developer و main).
- عند حدوث تضارب: إعادة الدمج محليًا وحل التعارض ثم push.

## سياسة Pull Request
- كل PR يجب أن يحتوي على وصف واضح للهدف والتغييرات وكيفية الاختبار.
- يجب أن يمر CI بنجاح قبل الدمج.
- مطلوب على الأقل مراجعة واحدة (أو وفق إعدادات CODEOWNERS).
- يُفضل استخدام عنوان وصيغة واضحة: [feature]، [bugfix]، [hotfix]...
- عند الدمج إلى main تأكد من إصدار و/أو تحديث changelog إن وجد.

## أوامر مفيدة
- إنشاء وفرع جديد من Developer:
  git checkout Developer
  git pull --ff-only origin Developer
  git checkout -b feature/اسم-الميزة

- رفع فرع:
  git push -u origin feature/اسم-الميزة

- دمج محلي من Developer:
  git checkout Developer
  git pull --ff-only origin Developer
  git merge --no-ff feature/اسم-الميزة

- إنشاء PR عبر gh CLI:
  gh pr create --base Developer --head feature/اسم-الميزة --title "عنوان" --body "الوصف"

---
التزم بهذه القواعد لبيئة تطوير آمنة ومنظمة. عدل أي نقطة حسب سياسة الفريق.