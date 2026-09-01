<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');

use App\Includes\Auth;
use App\Includes\Security;

$languageService = $GLOBALS['languageService'];
$auth = new Auth($database);
$current = $auth->currentUser();
if ($current !== null) {
    $roles = $current['roles'] ?? [];
    $target = in_array('agent', $roles, true) ? 'agent.php' : (in_array('customer', $roles, true) ? 'customer.php' : 'admin/admin.php');
    header('Location: ' . $target, true, 302);
    exit;
}
$siteSettings = (new \App\Includes\SiteSettingsService($database))->publicSettings((string) ($languageService->context()['code'] ?? 'ar'));
$siteName = (string) ($siteSettings['site_name_ar'] ?? 'منصة رحلة');
$languageContext = $languageService->context();
$languageCode = (string) ($languageContext['code'] ?? 'ar');
$languageDirection = (string) ($languageContext['direction'] ?? 'rtl');
$bootstrapCss = (string) ($languageContext['bootstrap_css'] ?? 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css');
$logoPath = (string) ($siteSettings['logo_path'] ?? '');
$logoHref = preg_match('#^uploads/[a-z0-9_/-]+\\.(?:jpg|jpeg|png|webp)$#i', $logoPath) === 1 ? Security::escape($logoPath) . '?v=' . rawurlencode((string) time()) : '';
?><!doctype html>
<html lang="<?= Security::escape($languageCode) ?>" dir="<?= Security::escape($languageDirection) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= Security::escape(Security::csrfToken()) ?>">
  <link rel="icon" href="favicon.ico" type="image/x-icon">
  <title>تسجيل الدخول | منصة حجوزات الباصات</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= Security::escape($bootstrapCss) ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/app.css?v=20260827-57">
  <style>
    :root{--login-ink:#102b43;--login-primary:#174a7e;--login-blue:#1e88c9;--login-gold:#d89b35;--login-muted:#668096;--login-border:#dce6ef}
    *{box-sizing:border-box}body.login-page{min-height:100vh;margin:0;display:grid;place-items:center;overflow-x:hidden;background:radial-gradient(circle at 8% 12%,rgb(30 136 201/.16),transparent 28%),radial-gradient(circle at 92% 88%,rgb(216 155 53/.14),transparent 28%),linear-gradient(145deg,#eef5f9,#fff 52%,#f4f7fa);font-family:Tajawal,system-ui,sans-serif;color:var(--login-ink)}
    .login-page:before{content:"";position:fixed;inset:auto auto -180px -120px;width:430px;height:430px;border:1px solid rgb(30 136 201/.13);border-radius:50%;box-shadow:0 0 0 34px rgb(30 136 201/.035),0 0 0 70px rgb(30 136 201/.025);pointer-events:none}
    .login-card{position:relative;width:min(920px,calc(100% - 32px));display:grid;grid-template-columns:1fr 1.14fr;overflow:hidden;border:1px solid rgb(255 255 255/.9);border-radius:28px;background:rgb(255 255 255/.9);box-shadow:0 30px 75px rgb(16 47 78/.16);backdrop-filter:blur(16px)}
    .login-visual{position:relative;isolation:isolate;display:flex;min-height:560px;flex-direction:column;justify-content:center;padding:44px 38px;overflow:hidden;background:linear-gradient(145deg,#102f55 0%,#174a7e 54%,#1e88c9 100%);color:#fff}.login-visual:before,.login-visual:after{content:"";position:absolute;z-index:-1;border-radius:50%;background:rgb(255 255 255/.07)}.login-visual:before{width:370px;height:370px;top:-155px;left:-135px}.login-visual:after{width:280px;height:280px;right:-125px;bottom:-120px;background:rgb(216 155 53/.18)}.login-dots{position:absolute;inset:0;z-index:-1;background-image:radial-gradient(rgb(255 255 255/.17) 1px,transparent 1px);background-size:24px 24px;mask-image:linear-gradient(to bottom,black,transparent 80%)}.login-mark{display:grid;place-items:center;width:68px;height:68px;margin-bottom:25px;border:1px solid rgb(255 255 255/.28);border-radius:21px;background:rgb(255 255 255/.13);box-shadow:0 14px 25px rgb(0 0 0/.15);font-size:1.8rem}.login-kicker{display:inline-flex;width:max-content;padding:7px 11px;border-radius:99px;background:rgb(255 255 255/.13);color:#fff3cc;font-size:.75rem;font-weight:900}.login-visual h2{max-width:310px;margin:17px 0 12px;font-size:clamp(1.55rem,3vw,2.15rem);line-height:1.45;font-weight:900;letter-spacing:-.03em}.login-visual p{max-width:320px;margin:0;color:#d9ebf5;line-height:1.9;font-size:.93rem}.login-benefits{display:grid;gap:11px;margin:30px 0 0;padding:0;list-style:none;color:#e8f5fa;font-size:.82rem;font-weight:800}.login-benefits li{display:flex;align-items:center;gap:9px}.login-benefits li:before{content:"✓";display:grid;place-items:center;width:21px;height:21px;border-radius:50%;background:rgb(216 155 53/.9);color:#102f55;font-weight:900}
    .login-content{padding:52px 54px 42px;background:#fff}.login-brand{display:flex;align-items:center;gap:12px;margin-bottom:32px}.login-logo{display:grid;place-items:center;width:48px;height:48px;border-radius:15px;background:linear-gradient(145deg,#174a7e,#1e88c9);color:#fff;box-shadow:0 9px 18px rgb(23 74 126/.22);font-size:1.35rem}.login-brand b{display:block;color:var(--login-ink);font-size:1.1rem;font-weight:900}.login-brand small{display:block;margin-top:3px;color:var(--login-muted);font-size:.75rem;font-weight:700}.login-eyebrow{display:inline-flex;padding:7px 11px;border-radius:99px;background:#fff5df;color:#a86c17;font-size:.73rem;font-weight:900}.login-content h1{margin:15px 0 8px;color:var(--login-ink);font-size:1.85rem;letter-spacing:-.03em}.login-description{max-width:410px;margin:0;color:var(--login-muted);font-size:.9rem;line-height:1.85}.login-form{display:grid;gap:15px;margin-top:26px}.login-form label{display:grid;gap:7px;margin:0;color:#405b70;font-size:.8rem;font-weight:900}.login-form input{width:100%;min-height:50px;padding:11px 14px;border:1px solid var(--login-border);border-radius:12px;background:#f9fbfd;color:var(--login-ink);font:inherit;outline:0;transition:border-color .2s ease,box-shadow .2s ease,background .2s ease}.login-form input:focus{border-color:var(--login-blue);background:#fff;box-shadow:0 0 0 4px rgb(30 136 201/.12)}.login-submit{width:100%;min-height:52px;margin-top:5px;border:0;border-radius:13px;background:linear-gradient(135deg,#174a7e,#1e88c9);box-shadow:0 11px 21px rgb(23 74 126/.22);color:#fff;font:900 .95rem Tajawal,sans-serif;cursor:pointer;transition:transform .2s ease,box-shadow .2s ease,background .2s ease}.login-submit:hover:not(:disabled){transform:translateY(-2px);background:linear-gradient(135deg,#123b66,#1679b3);box-shadow:0 15px 28px rgb(23 74 126/.28)}.login-submit:active{transform:scale(.98)}.login-submit:disabled{cursor:wait;opacity:.65}.login-message{min-height:24px;margin:0;color:#b85c52;font-size:.82rem;font-weight:800}.login-hint{margin:20px 0 0;padding-top:17px;border-top:1px solid #edf2f6;text-align:center;color:var(--login-muted);font-size:.8rem}.login-hint a{color:var(--login-primary);font-weight:900;text-decoration:none}.login-hint a:hover{text-decoration:underline;text-underline-offset:3px}
    @media(max-width:760px){.login-card{grid-template-columns:1fr;width:min(520px,calc(100% - 24px));border-radius:22px}.login-visual{min-height:185px;padding:27px 25px 24px}.login-mark,.login-benefits{display:none}.login-visual h2{margin:10px 0 4px;font-size:1.35rem}.login-visual p{max-width:440px;font-size:.81rem;line-height:1.7}.login-content{padding:31px 25px 28px}.login-content h1{font-size:1.5rem}}
    @media(max-width:420px){.login-content{padding:28px 20px 25px}.login-brand{margin-bottom:26px}.login-form{margin-top:22px}.login-visual{padding-inline:20px}}
    .login-mark img,.login-logo img{width:100%;height:100%;padding:8px;object-fit:contain;border-radius:inherit}.login-theme-toggle{position:absolute;top:18px;left:18px;z-index:2;display:inline-flex;align-items:center;gap:7px;padding:8px 11px;border:1px solid var(--login-border);border-radius:999px;background:rgb(255 255 255/.72);color:var(--login-ink);font:800 .72rem Tajawal,sans-serif;cursor:pointer}.login-theme-toggle:hover{background:#fff}.login-page.login-dark{background:radial-gradient(circle at 8% 12%,rgb(30 136 201/.18),transparent 28%),linear-gradient(145deg,#071523,#0f2235 54%,#10283d);color:#e2e8f0}.login-dark .login-card{border-color:#24415d;background:#12243a;box-shadow:0 30px 75px rgb(0 0 0/.35)}.login-dark .login-content{background:#12243a}.login-dark .login-brand b,.login-dark .login-content h1{color:#f8fafc}.login-dark .login-brand small,.login-dark .login-description,.login-dark .login-hint{color:#9fb2c7}.login-dark .login-form label{color:#cbd5e1}.login-dark .login-form input{border-color:#29445f;background:#0d1c2e;color:#f8fafc}.login-dark .login-form input:focus{background:#10253d;border-color:#4da3d8}.login-dark .login-hint{border-color:#29445f}.login-dark .login-hint a{color:#7dd3fc}.login-dark .login-theme-toggle{border-color:#3a5873;background:#18324b;color:#e2e8f0}@media(max-width:760px){.login-theme-toggle{top:12px;left:12px}}
  </style>
</head>
<body class="login-page" data-api-base="api/v1" data-language-code="<?= Security::escape($languageCode) ?>" data-language-direction="<?= Security::escape($languageDirection) ?>">
  <main class="login-card"><div id="login-language-slot" class="login-language-slot"></div><button class="login-theme-toggle" id="login-theme-toggle" type="button" aria-label="تفعيل الوضع الليلي"><span class="login-theme-icon">◐</span><span class="login-theme-label">ليلي</span></button>
    <section class="login-visual" aria-label="مزايا المنصة">
      <span class="login-dots"></span>
      <div class="login-mark"><?= $logoHref !== '' ? '<img src="' . $logoHref . '" alt="' . Security::escape($siteName) . '">' : '🚌' ?></div>
      <span class="login-kicker">رحلتك تبدأ من هنا</span>
      <h2>إدارة وحجز أكثر وضوحًا وراحة</h2>
      <p>منصة موحدة للعملاء والشركات والوكلاء لإدارة الرحلات والحجوزات بثقة.</p>
      <ul class="login-benefits">
        <li>وصول آمن حسب الصلاحية</li>
        <li>بيانات رحلات محدثة</li>
        <li>تجربة عربية سهلة الاستخدام</li>
      </ul>
    </section>
    <section class="login-content">
      <div class="login-brand"><span class="login-logo"><?= $logoHref !== '' ? '<img src="' . $logoHref . '" alt="' . Security::escape($siteName) . '">' : '🚌' ?></span><span><b><?= Security::escape($siteName) ?></b><small>بوابة موحدة لجميع الحسابات</small></span></div>
      <span class="login-eyebrow">مرحبًا بعودتك</span>
      <h1>تسجيل الدخول</h1>
      <p class="login-description">استخدم البريد الإلكتروني أو اسم المستخدم أو رقم الجوال، وسيتم توجيهك تلقائيًا إلى واجهتك المناسبة.</p>
      <form id="login-form" class="login-form">
        <label>البريد الإلكتروني أو اسم المستخدم أو رقم الجوال<input name="identifier" autocomplete="username" required></label>
        <label>كلمة المرور<input name="password" type="password" autocomplete="current-password" required></label>
        <button class="login-submit" type="submit">تسجيل الدخول</button>
        <div id="login-message" class="login-message" aria-live="polite"></div>
      </form>
      <div class="login-hint"><a href="customer.php">إنشاء حساب عميل أو البحث عن رحلة</a></div>
    </section>
  </main>
  <script src="assets/js/i18n.js?v=20260901-13" defer></script>
  <script>
    const loginThemeToggle=document.getElementById('login-theme-toggle');const applyLoginTheme=(theme)=>{const dark=theme==='dark';document.body.classList.toggle('login-dark',dark);if(loginThemeToggle){loginThemeToggle.querySelector('.login-theme-label').textContent=dark?'نهاري':'ليلي';loginThemeToggle.querySelector('.login-theme-icon').textContent=dark?'☀':'◐';loginThemeToggle.setAttribute('aria-label',dark?'تفعيل الوضع النهاري':'تفعيل الوضع الليلي');}};applyLoginTheme(localStorage.getItem('rihla-theme')||'light');loginThemeToggle?.addEventListener('click',()=>{const next=document.body.classList.contains('login-dark')?'light':'dark';localStorage.setItem('rihla-theme',next);applyLoginTheme(next);});
    const form=document.getElementById('login-form'),message=document.getElementById('login-message'),csrf=document.querySelector('meta[name="csrf-token"]').content;
    const pageUrl=new URL(location.href);
    if(pageUrl.searchParams.has('password')||pageUrl.searchParams.has('identifier')){pageUrl.searchParams.delete('password');pageUrl.searchParams.delete('identifier');history.replaceState(null,document.title,pageUrl.pathname+(pageUrl.search?('?'+pageUrl.searchParams.toString()):'')+pageUrl.hash);}
    const apiEndpoint=new URL('api/v1/index.php',location.href);
    const apiCall=async(route,options={})=>{const requestUrl=new URL(apiEndpoint.href);requestUrl.searchParams.set('route',route);const response=await fetch(requestUrl.href,{credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':csrf,...(options.headers||{})},...options});let payload={};try{payload=await response.json();}catch(_error){}if(!response.ok||!payload.success)throw new Error(payload.message||'تعذر إتمام العملية.');return payload;};
    const wait=(milliseconds)=>new Promise(resolve=>setTimeout(resolve,milliseconds));
    const goAfterAuth=async(user)=>{let verifiedUser=null;let lastError=null;for(const delay of [0,180,500]){if(delay)await wait(delay);try{const session=await apiCall('auth/me',{method:'GET'});if(session.data?.user){verifiedUser=session.data.user;break;}}catch(error){lastError=error;}}if(!verifiedUser||!Array.isArray(verifiedUser.roles)){throw lastError||new Error('تم قبول الدخول لكن لم تثبت الجلسة على هذا الجهاز. يرجى المحاولة مرة أخرى.');}const roles=verifiedUser.roles||[];const allowedReturn=new URLSearchParams(location.search).get('return')||'';const target=roles.includes('agent')?'agent.php':roles.includes('customer')?'customer.php':(allowedReturn.startsWith('admin/')?allowedReturn:'admin/admin.php');const destination=new URL(target,location.href);if(destination.origin!==location.origin)throw new Error('وجهة الدخول غير صالحة.');location.replace(destination.href);};
    const showLoginOtp=async(challenge,identifier)=>{const content=form.parentElement;const expires=Date.parse(String(challenge.expires_at||'').replace(' ','T'))||Date.now()+300000;let resendAt=Date.now()+Number(challenge.resend_after_seconds||60);content.innerHTML=`<form id="login-otp-form" class="login-form"><label>رمز التحقق<input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" pattern="[0-9]{4,8}" placeholder="أدخل الرمز المكون من 6 أرقام" required></label><div class="login-otp-note" id="login-otp-note"></div>${(challenge.available_channels||[]).length>1?'<label>قناة الإرسال<select id="login-otp-channel">'+(challenge.available_channels||[]).map(item=>'<option value="'+item.channel+'">'+item.label+'</option>').join('')+'</select></label>':''}<button class="login-submit" type="submit">تحقق</button><button class="login-otp-resend" id="login-otp-resend" type="button" disabled>إعادة إرسال الرمز</button><p class="login-message" id="login-otp-message" aria-live="polite"></p></form>`;const otpForm=document.getElementById('login-otp-form'),note=document.getElementById('login-otp-note'),otpMessage=document.getElementById('login-otp-message'),channelSelect=document.getElementById('login-otp-channel'),resend=document.getElementById('login-otp-resend');let timer=setInterval(()=>{const left=Math.max(0,Math.ceil((expires-Date.now())/1000));note.textContent=left?`تنتهي صلاحية الرمز خلال ${Math.floor(left/60)}:${String(left%60).padStart(2,'0')}`:'انتهت صلاحية رمز التحقق، اطلب رمزًا جديدًا.';},1000);let resendTimer=setInterval(()=>{const left=Math.max(0,Math.ceil((resendAt-Date.now())/1000));resend.disabled=left>0;resend.textContent=left?`إعادة الإرسال بعد ${left} ثانية`:'إعادة إرسال الرمز';if(left<1){clearInterval(resendTimer);}},1000);channelSelect?.addEventListener('change',async()=>{channelSelect.disabled=true;try{const next=await apiCall('auth/otp/login/request',{method:'POST',body:JSON.stringify({identifier,channel:channelSelect.value})});clearInterval(timer);clearInterval(resendTimer);await showLoginOtp(next.data,identifier);}catch(error){channelSelect.disabled=false;otpMessage.textContent=error.message;}});resend.addEventListener('click',async()=>{if(resend.disabled)return;try{const next=await apiCall('auth/otp/resend',{method:'POST',body:JSON.stringify({challenge_id:challenge.challenge_id})});resendAt=Date.now()+Number(next.data.resend_after_seconds||60);resendTimer=setInterval(()=>{const left=Math.max(0,Math.ceil((resendAt-Date.now())/1000));resend.disabled=left>0;resend.textContent=left?`إعادة الإرسال بعد ${left} ثانية`:'إعادة إرسال الرمز';},1000);otpMessage.textContent='تمت إعادة إرسال رمز التحقق.';}catch(error){otpMessage.textContent=error.message;}});otpForm.addEventListener('submit',async(event)=>{event.preventDefault();const submit=otpForm.querySelector('button[type="submit"]');submit.disabled=true;try{const result=await apiCall('auth/otp/verify',{method:'POST',body:JSON.stringify({challenge_id:challenge.challenge_id,code:new FormData(otpForm).get('code')})});clearInterval(timer);clearInterval(resendTimer);await goAfterAuth(result.data.user);}catch(error){otpMessage.textContent=error.message;submit.disabled=false;}});otpForm.querySelector('input')?.focus();};
    form.addEventListener('submit',async(e)=>{e.preventDefault();message.textContent='';const submit=form.querySelector('button');submit.disabled=true;try{const values=Object.fromEntries(new FormData(form));const options=await apiCall('auth/otp/channels');if(Number(options.data.settings?.enabled)!==1){const payload=await apiCall('auth/login',{method:'POST',body:JSON.stringify(values)});await goAfterAuth(payload.data.user);return;}const challenge=await apiCall('auth/otp/login/request',{method:'POST',body:JSON.stringify({identifier:values.identifier})});await showLoginOtp(challenge.data,values.identifier);}catch(error){message.textContent=error.message;}finally{submit.disabled=false;}});
  </script>
</body>
</html>
