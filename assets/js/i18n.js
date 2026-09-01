(function () {
  'use strict';
  const body = document.body;
  const html = document.documentElement;
  const apiBase = body?.dataset.apiBase || 'api/v1';
  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
  const apiUrl = (route) => `${apiBase}/index.php?route=${encodeURIComponent(route)}`;
  const initialCode = body?.dataset.languageCode || html?.getAttribute('lang') || 'ar';
  const initialDirection = body?.dataset.languageDirection || html?.getAttribute('dir') || 'rtl';
  const state = { context: { language: { code: initialCode, direction: initialDirection }, languages: [] }, translations: {} };

  function applyDocument(language) {
    if (!language) return;
    const code = String(language.code || initialCode);
    const direction = language.direction === 'ltr' ? 'ltr' : 'rtl';
    html.setAttribute('lang', code);
    html.setAttribute('dir', direction);
    body?.setAttribute('data-language-code', code);
    body?.setAttribute('data-language-direction', direction);
    body?.classList.toggle('language-ltr', direction === 'ltr');
    body?.classList.toggle('language-rtl', direction === 'rtl');
  }

  function t(key, fallback) {
    return state.translations[key] || fallback || key;
  }

  function switcherMarkup() {
    const languages = Array.isArray(state.context.languages) ? state.context.languages : [];
    if (languages.length < 2) return '';
    const current = String(state.context.language?.code || initialCode);
    return `<label class="rihla-language-switcher" title="${t('common.language', 'Language')}"><span class="rihla-language-icon" aria-hidden="true">文</span><span class="sr-only">${t('common.language', 'Language')}</span><select data-rihla-language aria-label="${t('common.language', 'Language')}">${languages.map((item) => { const code = String(item.code || '').replace(/[^a-zA-Z-]/g, '').toLowerCase(); const flag = code === 'ar' ? '🇸🇦' : code === 'en' ? '🇬🇧' : '🌐'; const name = code === 'ar' ? 'العربية' : code === 'en' ? 'English' : String(item.name_ar || item.name_native || code.toUpperCase()); return `<option value="${code}" ${code === current ? 'selected' : ''}>${flag} ${name}</option>`; }).join('')}</select></label>`;
  }

  function setElementLabel(selector, value) {
    document.querySelectorAll(selector).forEach((node) => {
      const child = node.querySelector(':scope > span:not(.notification-badge), :scope > b');
      if (child && node.children.length <= 2) { if (child.textContent !== value) child.textContent = value; }
      else if (node.children.length === 0) { if (node.textContent !== value) node.textContent = value; }
    });
  }

  const publicEnglish = new Map(Object.entries({
    'منصة رحلتي':'Rihla Platform','الرئيسية':'Home','من نحن':'About us','اتصل بنا':'Contact us','السياسة والخصوصية':'Privacy policy','مركز المطورين / API':'Developers / API','ليلي':'Night mode','نهاري':'Light mode','منصة حجز رحلات الباصات':'Bus Trip Booking Platform','احجز رحلتك بسهولة وأمان':'Book Your Trip Easily and Safely','بين المدن المتاحة':'Between Available Cities','ابحث عن الرحلة المناسبة، ثم تابع الحجز بخطوات واضحة.':'Search for the right trip, then continue with clear steps.','من':'From','إلى':'To','اختر المدينة':'Select city','تاريخ الرحلة':'Trip date','نوع الباص':'Bus type','كل الأنواع':'All types','اقتصادي':'Economy','سياحي':'Tourist','بحث':'Search','اختر تاريخ الرحلة بسرعة':'Choose a trip date quickly','اليوم':'Today','غدًا':'Tomorrow','الرحلات المتاحة':'Available trips','لا توجد رحلات مفتوحة وقابلة للحجز حاليًا':'No open and bookable trips are currently available','استخدم نموذج البحث لاختيار مدينة الانطلاق والوجهة وتاريخ السفر.':'Use the search form to choose the departure city, destination, and travel date.','شركات النقل المتاحة':'Available transport companies','المسارات الشائعة':'Popular routes','بيانات الشركات تأتي مباشرة من النظام.':'Company data comes directly from the system.','عملة التشغيل':'Operating currency','روابط سريعة':'Quick links','معلومات الاستخدام':'Usage information','البحث عن رحلة':'Search for a trip','احجز رحلتك بسهولة وأمان':'Book your trip easily and safely','تسجيل الدخول':'Sign in','إنشاء حساب جديد':'Create a new account','تسجيل الدخول أو إنشاء حساب':'Sign in or create an account','فتح حسابي':'Open my account','مرحبًا بك':'Welcome','سجّل الدخول لمتابعة حجوزاتك، أو أنشئ حسابًا جديدًا خلال دقائق.':'Sign in to manage your bookings, or create a new account in minutes.','تعديل بياناتي':'Edit my profile','بياناتي':'My profile','حجوزاتي':'My bookings','إشعاراتي':'Notifications','إشعاراتي':'Notifications','تسجيل الدخول أو إنشاء حساب':'Sign in or create an account'}));
  const adminEnglish = new Map(Object.entries({
    'لوحة التحكم':'Dashboard','الرئيسية':'Home','الحجوزات':'Bookings','الرحلات':'Trips','العملاء':'Customers','الوكلاء':'Agents','الشركات':'Companies','المستخدمون':'Users','التقارير':'Reports','الإعدادات':'Settings','إدارة اللغات':'Languages','الترجمة المركزية':'Translations','المدن':'Cities','الدول':'Countries','المحطات':'Stations','المسارات':'Routes','العملات':'Currencies','أسعار الصرف':'Exchange rates','الصلاحيات':'Permissions','مركز المطورين / API':'Developer / API','تسجيل الخروج':'Sign out','تعديل بيانات المستخدم':'Edit user profile','اللغة':'Language','اختيار اللغة':'Choose language','العربية':'Arabic','English':'English','مرحبًا':'Welcome','مدير رئيسي':'Super administrator','مدير شركة':'Company administrator','موظف حجوزات':'Booking officer','محاسب':'Accountant','موظف دعم':'Support officer','بحث':'Search','بحث سريع':'Quick search','انتقال سريع إلى قسم…':'Quick jump to a section…','الإشعارات':'Notifications','تبديل الوضع':'Toggle theme','تكبير لوحة التحكم':'Fullscreen dashboard','تصغير لوحة التحكم':'Exit fullscreen','إحصاءات اليوم':'Today’s statistics','اليوم':'Today','هذا الأسبوع':'This week','هذا الشهر':'This month','مقارنة بالشهر السابق':'Compared with last month','مقارنة بالأسبوع السابق':'Compared with last week','إجمالي الحجوزات':'Total bookings','الحجوزات المؤكدة':'Confirmed bookings','الحجوزات المعلقة':'Pending bookings','الحجوزات الملغاة':'Cancelled bookings','الإيرادات':'Revenue','العملاء الجدد':'New customers','الرحلات النشطة':'Active trips','لا توجد بيانات':'No data available','لا توجد نتائج':'No results found','عرض المزيد':'View more','المزيد من المعلومات':'More information','حفظ':'Save','حفظ التغييرات':'Save changes','إلغاء':'Cancel','إغلاق':'Close','إضافة':'Add','تعديل':'Edit','حذف':'Delete','الإجراء':'Action','الإجراءات':'Actions','الحالة':'Status','نشط':'Active','نشطة':'Active','غير نشط':'Inactive','غير نشطة':'Inactive','موقوف':'Inactive','معلق':'Suspended','المستخدم':'User','الاسم':'Name','البريد الإلكتروني':'Email','البريد':'Email','الهاتف':'Phone','التاريخ':'Date','الوقت':'Time','المبلغ':'Amount','الإجمالي':'Total','التفاصيل':'Details','البيانات':'Data','قائمة المستخدمين':'Users list','إجمالي الحسابات':'Total accounts','الحسابات النشطة':'Active accounts','إدارة الصلاحيات':'Permissions management','كل الحالات':'All statuses','مسح الفلاتر':'Clear filters','إضافة مستخدم':'Add user','إضافة عميل':'Add customer','إضافة وكيل':'Add agent','إضافة رحلة':'Add trip','إضافة شركة':'Add company','إضافة لغة':'Add language','إضافة ترجمة':'Add translation','قائمة اللغات':'Languages list','اللغات المسجلة':'Registered languages','الكود':'Code','الاتجاه':'Direction','الافتراضية':'Default','الترجمة':'Translation','مفتاح الترجمة':'Translation key','القيمة':'Value','ترجمة جديدة':'New translation','حفظ الترجمة':'Save translation','تم الحفظ بنجاح':'Saved successfully','تم التحديث بنجاح':'Updated successfully','حدث خطأ غير متوقع':'An unexpected error occurred','تعذر تحميل البيانات':'Unable to load data','الرحلة':'Trip','رقم الحجز':'Booking number','اسم المسافر':'Passenger name','المغادرة':'Departure','الوصول':'Arrival','المقاعد':'Seats','المحطة الأولى':'First station','المحطة الأخيرة':'Last station','سعر التذكرة':'Ticket price','شركة النقل':'Transport company','نوع الباص':'Bus type','اقتصادي':'Economy','سياحي':'Tourist','من':'From','إلى':'To','اختر':'Choose','اختر المدينة':'Choose city','اختر الشركة':'Choose company','اختر التاريخ':'Choose date','كل الأنواع':'All types','كل الشركات':'All companies','كل المدن':'All cities','اسم الموقع':'Site name','الوصف المختصر':'Short description','الإعدادات العامة':'General settings','الإعدادات المالية':'Financial settings','هوية الموقع ومحتواه':'Site identity and content','حقوق النشر والتذييل':'Footer and copyright','جاهز للحفظ':'Ready to save','جارٍ الحفظ…':'Saving…','تم الحفظ':'Saved','غير محدد':'Not specified','لا يوجد مسار مطابق':'No matching section','صفحة مخصصة للحسابات والصلاحيات فقط':'A dedicated page for accounts and permissions only','الحالة الحالية':'Current status','مفعل':'Enabled','معطل':'Disabled','نعم':'Yes','لا':'No','الكل':'All','عرض':'View','عودة':'Back','التالي':'Next','السابق':'Previous','إدارة النظام':'System management','ملخص التشغيل':'Operations summary','تهيئة النظام':'System setup','الإدارة الفرعية':'Sub-management','محطات التشغيل':'Operating stations','إعدادات المالية':'Financial settings','شحن رصيد الوكيل':'Agent balance top-up','كشف حساب الوكيل':'Agent statement','كشف حساب الشركة':'Company statement','المدفوعات':'Payments','رسائل الدعم':'Support messages','التذاكر':'Tickets','الجلسة':'Session','اختبار':'Test','نظرة عامة على المنصة':'Platform overview','منصة تشغيل موحدة تعتمد على البيانات الحقيقية، مع مقارنة أسبوعية وشهرية داخل كل بطاقة':'A unified operations platform based on real data, with weekly and monthly comparisons in every card','المبيعات والأرباح':'Sales and profits','الربح الحالي:':'Current profit:','ربح':'Profit','المفتوحة:':'Open:','المكتملة:':'Completed:','المتاح:':'Available:','نسبة الإلغاء:':'Cancellation rate:','الإلغاءات':'Cancellations','المسارات والرحلات':'Routes and trips','حالة كل رحلة ومقطع':'Status of each trip and segment','وتقسيم حجوزات':'and booking breakdown','المباشرين':'direct customers','رحلات':'Trips','رحلة':'Trip','لا توجد رحلات مجدولة':'No scheduled trips','لا توجد حجوزات وكيلة كافية لتحديد الأعلى':'Not enough agent bookings to determine the top performer','والمبيعات حسب':'and sales by','كافية للمقارنة':'enough for comparison','حالة':'Status','في الفترة':'during the period','المبيعات حسب الشركة':'Sales by company','حسب الوكيل':'By agent','المحجوز':'Booked','إشغال':'Occupancy','Total':'Total','Activeون':'Active','Cancelات':'Cancellations','بيانات الرحلات المحدثة':'Updated trip data','بيانات الرحلات':'Trip data','منصة موحدة للعملاء والشركات والوكلاء لإدارة الرحلات والحجوزات بثقة.':'A unified platform for customers, companies, and agents to manage trips and bookings with confidence.','منصة تشغيل موحدة تعتمد على البيانات الحقيقية، مع مقارنة أسبوعية وشهرية داخل كل بطاقة.':'A unified operations platform based on real data, with weekly and monthly comparisons in every card.','الأسبوع':'Week','الشهر':'Month','المسارات الرئيسية':'Main routes','إدارة المسارات':'Route management','إدارة الرحلات':'Trip management','الإدارة الفرعية':'Sub-management','إعدادات الوكلاء المالية':'Agent financial settings','النشطون':'Active','النشطة':'Active','المحجوزة':'Booked','بيانات الرحلات المحدثة':'Updated trip data','بيانات رحلات محدثة':'Updated trip data','وصول آمن حسب الصلاحية':'Secure role-based access','تجربة عربية سهلة الاستخدام':'An easy Arabic experience','رحلتك تبدأ من هنا':'Your journey starts here','إدارة وحجز أكثر وضوحًا وراحة':'Clearer and easier trip management and booking','بوابة موحدة لجميع الحسابات':'A unified portal for all accounts','مرحبًا بعودتك':'Welcome back','استخدم البريد الإلكتروني أو اسم المستخدم أو رقم الجوال، وسيتم توجيهك تلقائيًا إلى واجهتك المناسبة.':'Use your email, username, or mobile number and you will be directed to the right interface.','البريد الإلكتروني أو اسم المستخدم أو رقم الجوال':'Email, username, or mobile number','كلمة المرور':'Password','إنشاء حساب عميل أو البحث عن رحلة':'Create a customer account or search for a trip','ملخص التشغيل':'Operations summary','حالة كل رحلة ومقطع':'Status of every trip and segment','إجمالي الحجوزات':'Total bookings','إجمالي الرحلات':'Total trips','المقارنة':'Comparison','الربح السابق:':'Previous profit:','الربح الحالي:':'Current profit:','نسبة الإشغال:':'Occupancy rate:','إجمالي العملاء':'Total customers','توجد حجوزات':'Bookings available','العملاء المباشرين':'Direct customers','العملاء':'Customers','الشركات':'Companies','الوكلاء':'Agents','صفحة التقارير':'Reports page','التنبيهات':'Alerts','معلومات الجلسة':'Session information','البيانات الحقيقية':'real data','البيانات':'Data','الفرعية':'Sub-routes','رسائل':'Messages','رسائل الدعم':'Support messages','المسارات الرئيسية':'Main routes','المسارات':'Routes','حالة كل رحلة وإجمالي الحجوزات وتقسيم حجوزات الوكلاء والعملاء المباشرين.':'Status of every trip, total bookings, and the breakdown between agent and direct-customer bookings.','حالة كل رحلة وإجمالي الحجوزات وتقسيم حجوزات الوكلاء والعملاء المباشرين':'Status of every trip, total bookings, and the breakdown between agent and direct-customer bookings','الربح السابق:':'Previous profit:','الأعلى':'Top','الأدنى':'Bottom','المجدولة':'Scheduled','المكتملة':'Completed','المفتوحة':'Open','المباشرين':'Direct customers','العملاء المباشرين':'Direct customers','إجمالي الحجوزات':'Total bookings','إجمالي المبيعات':'Total sales','الإشغال':'Occupancy','ملخص':'Summary','تشغيل':'Operations','توجد حجوزات وكيلة كافية لتحديد الأعلى':'There are enough agent bookings to determine the top performer','لا توجد حجوزات وكيلة كافية لتحديد الأعلى':'Not enough agent bookings to determine the top performer','لا توجد رحلات مجدولة':'No scheduled trips','توجد رحلات مجدولة':'Scheduled trips available','إدارة المنصة':'Platform management','منصة Bookings':'Bookings platform','Seats والإشغال':'Seats & occupancy','والإشغال':'and occupancy','Profit السابقة:':'Previous profit:','الربح السابق:':'Previous profit:','Trips Today':'Today’s trips','Status كل Trip وإجمالي Bookings and booking breakdown Agents والعملاء direct customers.':'Status of every trip, total bookings, and agent/direct customer booking breakdown.','المتبقي':'Remaining','المؤكد':'Confirmed','المعلق':'Pending','الملغى':'Cancelled','الملغاة':'Cancelled'
  }));
  function applyPublicEnglish() {
    if (state.context.language?.code !== 'en') return;
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    const nodes = []; let node;
    while ((node = walker.nextNode())) nodes.push(node);
    const dictionary = new Map([...publicEnglish, ...adminEnglish, ...Object.entries(state.translations || {})]);
    nodes.forEach((textNode) => { let raw = textNode.nodeValue || ''; if (!raw.trim()) return; let translated = raw; [...dictionary.entries()].sort((a,b)=>b[0].length-a[0].length).forEach(([source,target])=>{ const pattern = new RegExp(`(?<![ء-ي])${source.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')}(?![ء-ي])`,'g'); translated = translated.replace(pattern,target); }); if (translated !== raw) textNode.nodeValue = translated; });
    document.querySelectorAll('input[placeholder], textarea[placeholder]').forEach((input) => { let value = input.getAttribute('placeholder') || ''; [...dictionary.entries()].sort((a,b)=>b[0].length-a[0].length).forEach(([source,target])=>{ const pattern = new RegExp(`(?<![ء-ي])${source.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')}(?![ء-ي])`,'g'); value=value.replace(pattern,target); }); input.setAttribute('placeholder', value); });
    document.querySelectorAll('[aria-label],[title]').forEach((node) => ['aria-label','title'].forEach((attribute) => { let value=node.getAttribute(attribute)||''; [...dictionary.entries()].sort((a,b)=>b[0].length-a[0].length).forEach(([source,target])=>{ const pattern=new RegExp(`(?<![ء-ي])${source.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')}(?![ء-ي])`,'g'); value=value.replace(pattern,target); }); node.setAttribute(attribute,value); }));
    if (state.context.language?.code === 'en') { const digitWalker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT); const digitNodes=[]; let digitNode; while ((digitNode=digitWalker.nextNode())) digitNodes.push(digitNode); digitNodes.forEach((node)=>{ node.nodeValue=(node.nodeValue||'').replace(/[٠-٩]/g,(digit)=>String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit))); }); }
  }

  function applyKnownLabels() {
    const labels = [
      ['.nav-link[data-public-page="home"], .public-mobile-link[data-public-page="home"]', 'nav.home', 'الرئيسية'],
      ['.nav-link[data-public-page="about"], .public-mobile-link[data-public-page="about"]', 'nav.about', 'من نحن'],
      ['.nav-link[data-public-page="contact"], .public-mobile-link[data-public-page="contact"]', 'nav.contact', 'اتصل بنا'],
      ['.nav-link[data-public-page="privacy"], .public-mobile-link[data-public-page="privacy"]', 'nav.privacy', 'السياسة والخصوصية'],
      ['[data-dash="home"] .template-nav-label', 'nav.home', 'الرئيسية'],
      ['[data-dash="bookings"] .template-nav-label', 'nav.bookings', 'الحجوزات'],
      ['[data-dash="trips"] .template-nav-label', 'nav.trips', 'الرحلات'],
      ['.login-content h1', 'auth.login', 'تسجيل الدخول'],
      ['.login-submit', 'auth.login', 'تسجيل الدخول'],
      ['.login-hint a', 'auth.register', 'إنشاء حساب عميل أو البحث عن رحلة'],
    ];
    labels.forEach(([selector, key, fallback]) => setElementLabel(selector, t(key, fallback)));
    document.querySelectorAll('[data-dash="languages"] .template-nav-label').forEach((node) => { node.textContent = t('nav.languages', 'إدارة اللغات'); });
    document.querySelectorAll('[data-dash="translations"] .template-nav-label').forEach((node) => { node.textContent = t('nav.translations', 'الترجمة المركزية'); });
  }

  function mountSwitcher() {
    const markup = switcherMarkup();
    if (!markup) return;
    const isMobile = window.matchMedia?.('(max-width: 760px)').matches;
    const mobileTarget = document.querySelector('#public-mobile-drawer .public-mobile-account');
    const desktopTarget = document.querySelector('.profile-menu-language-slot') || document.querySelector('#login-language-slot') || document.querySelector('.public-header-actions') || document.querySelector('.site-header .nav-container');
    const target = isMobile && mobileTarget ? mobileTarget : desktopTarget;
    if (!target) return;
    document.querySelectorAll('.rihla-language-switcher').forEach((node) => { if (node.parentElement !== target) node.remove(); });
    if (!target.querySelector('[data-rihla-language]')) target.insertAdjacentHTML('afterbegin', markup);
    const selector = target.querySelector('[data-rihla-language]');
    if (!selector || selector.dataset.bound === '1') return;
    selector.dataset.bound = '1';
    selector.addEventListener('change', async (event) => {
      const code = String(event.currentTarget.value || '');
      if (!code) return;
      event.currentTarget.disabled = true;
      try {
        const response = await fetch(apiUrl('language/set'), { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(csrf() ? { 'X-CSRF-Token': csrf() } : {}) }, body: JSON.stringify({ code }) });
        const payload = await response.json();
        if (!response.ok || !payload.success) throw new Error(payload.message || 'تعذر تغيير اللغة.');
        const url = new URL(location.href);
        url.searchParams.set('lang', code);
        location.assign(url.toString());
      } catch (error) {
        event.currentTarget.disabled = false;
        window.dispatchEvent(new CustomEvent('rihla-language-error', { detail: error.message }));
      }
    });
  }

  function installStyle() {
    if (document.getElementById('rihla-i18n-style')) return;
    const style = document.createElement('style'); style.id = 'rihla-i18n-style';
    style.textContent = `.rihla-language-switcher{display:inline-flex;align-items:center;gap:6px;min-height:36px;padding:4px 8px;border:1px solid var(--dash-border,#dce6ef);border-radius:10px;background:var(--dash-card,#fff);color:inherit;font:700 .75rem Tajawal,sans-serif}.rihla-language-switcher select{max-width:130px;border:0;outline:0;background:transparent;color:inherit;font:inherit;cursor:pointer}.public-header-actions .rihla-language-switcher{border-color:var(--public-border,#dce6ef);background:var(--public-card,#fff)}.login-language-slot{position:absolute;top:18px;right:18px;z-index:3}.login-language-slot .rihla-language-switcher{background:rgba(255,255,255,.78)}.login-dark .login-language-slot .rihla-language-switcher{background:#18324b;border-color:#3a5873;color:#e2e8f0}@media(max-width:760px){.login-language-slot{top:12px;right:12px}.rihla-language-switcher select{max-width:90px}}`;
    document.head.append(style);
  }

  async function load() {
    applyDocument(state.context.language);
    try {
      const response = await fetch(apiUrl('language/context'), { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
      const payload = await response.json();
      if (!response.ok || !payload.success) return;
      const languageData = payload.data?.language || state.context.language;
      state.context.language = languageData;
      state.context.languages = languageData?.languages || [];
      state.translations = payload.data?.translations || {};
      window.RihlaI18n = { ...state, t, applyDocument, reload: () => location.reload() };
      applyDocument(state.context.language);
      mountSwitcher();
      applyKnownLabels();
      applyPublicEnglish();
    } catch (_) {
      window.RihlaI18n = { ...state, t, applyDocument, reload: () => location.reload() };
    }
  }

  installStyle();
  let observerScheduled = false;
  const observer = new MutationObserver(() => {
    if (observerScheduled) return;
    observerScheduled = true;
    requestAnimationFrame(() => { observerScheduled = false; mountSwitcher(); applyKnownLabels(); applyPublicEnglish(); });
  });
  observer.observe(document.body, { childList: true, subtree: true });
  window.addEventListener('rihla-language-error', (event) => {
    const message = event.detail || 'تعذر تغيير اللغة.';
    const toast = document.createElement('div');
    toast.className = 'toast error';
    toast.textContent = message;
    document.body.append(toast);
    setTimeout(() => toast.remove(), 4200);
  });
  load();
})();
